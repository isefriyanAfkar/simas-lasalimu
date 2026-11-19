<?php

namespace App\Domains\Academic\Services;

use App\Domains\Academic\Enums\AksiAkademik;
use App\Domains\Academic\Models\PenempatanRombel;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Enums\StatusSiswa;
use App\Domains\People\Models\Siswa;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The only place that writes rombel placements (penempatan_rombel).
 *
 * Rules:
 * - only an active student is placed, moved, or has a placement deleted;
 *   the placement of a student who left is history
 * - only a rombel of the active school year or a later one; a placement of
 *   an earlier school year is never written or deleted. Without an active
 *   school year nothing is placed
 * - tahun_ajaran_id is always the rombel's school year (also enforced by a
 *   foreign key on both columns)
 * - at most one rombel per student per school year (also a unique index).
 *   tempatkan() refuses a student who already has a rombel that year;
 *   pindahkan() requires one and replaces it. No method does both
 * - kapasitas counts only active students; an empty kapasitas means no
 *   limit. A full rombel refuses a placement or a move into it
 * - every change is logged explicitly (log "akademik", subject the
 *   student), with the rombel as "X-1 2026/2027"
 *
 * Every write locks the student row first and then the rombel row, the same
 * order as SiswaService::aktifkanKembali(), so two writes for the same
 * student or into the same rombel run one after the other. The capacity is
 * enforced with a locking count (RombelSiswa::jumlahSiswaAktifTerkunci()):
 * correctness never depends on what the transaction read before. As a
 * second layer, nothing is read without a lock before the rombel lock is
 * taken. A deadlock (rare: a student coming back into the rombel at the same
 * moment) is retried.
 */
class PenempatanRombelService
{
    use DetectsConcurrencyErrors;

    public const string PESAN_COBA_LAGI = 'Sedang ada perubahan lain pada rombel ini. Coba lagi untuk siswa ini.';

    private const int ATTEMPTS = 3;

    public function __construct(
        private PeriodeAkademik $periodeAkademik,
        private RombelSiswa $rombelSiswa,
        private UserService $userService,
    ) {}

    /**
     * @throws PelanggaranAturanAkademik
     */
    public function tempatkan(User $pelaku, Siswa $siswa, Rombel $rombel): PenempatanRombel
    {
        $this->ensureCanManage($pelaku, $siswa);

        return DB::transaction(function () use ($pelaku, $siswa, $rombel): PenempatanRombel {
            $siswa = $this->lockStudent($siswa);
            $rombel = $this->lockRombel($rombel);
            $siswa->load('user');

            $this->ensureActive($siswa);
            $this->ensureNotPast($rombel->tahunAjaran);

            $ada = $this->placementIn($siswa, $rombel->tahun_ajaran_id);

            if ($ada !== null) {
                throw PelanggaranAturanAkademik::siswaSudahDiRombel($siswa->user->name, $ada->rombel->nama);
            }

            $this->ensureRoom($rombel);

            $penempatan = PenempatanRombel::create([
                'siswa_id' => $siswa->getKey(),
                'rombel_id' => $rombel->getKey(),
                'tahun_ajaran_id' => $rombel->tahun_ajaran_id,
            ]);

            $this->log(AksiAkademik::SiswaDitempatkan, $pelaku, $siswa, ['attributes' => ['rombel' => AktivitasAkademik::label($rombel)]]);

            return $penempatan;
        }, self::ATTEMPTS);
    }

    /**
     * Move a student to $rombel within the rombel's school year.
     *
     * @throws PelanggaranAturanAkademik
     */
    public function pindahkan(User $pelaku, Siswa $siswa, Rombel $rombel): PenempatanRombel
    {
        $this->ensureCanManage($pelaku, $siswa);

        return DB::transaction(function () use ($pelaku, $siswa, $rombel): PenempatanRombel {
            $siswa = $this->lockStudent($siswa);
            $rombel = $this->lockRombel($rombel);
            $siswa->load('user');

            $this->ensureActive($siswa);
            $this->ensureNotPast($rombel->tahunAjaran);

            $penempatan = $this->placementIn($siswa, $rombel->tahun_ajaran_id);

            if ($penempatan === null) {
                throw PelanggaranAturanAkademik::siswaBelumPunyaRombel($siswa->user->name, $rombel->tahunAjaran->nama);
            }

            if ($penempatan->rombel_id === $rombel->getKey()) {
                throw PelanggaranAturanAkademik::siswaSudahDiRombel($siswa->user->name, $rombel->nama);
            }

            $this->ensureRoom($rombel);

            $lama = AktivitasAkademik::label($penempatan->rombel);
            $penempatan->update(['rombel_id' => $rombel->getKey()]);

            $this->log(AksiAkademik::SiswaDipindahRombel, $pelaku, $siswa, [
                'attributes' => ['rombel' => AktivitasAkademik::label($rombel)],
                'old' => ['rombel' => $lama],
            ]);

            return $penempatan;
        }, self::ATTEMPTS);
    }

    /**
     * Place each student on its own, so one failure does not undo the
     * others. A student who already has a rombel in that school year is
     * skipped, never moved. A deadlock or lock timeout that outlasts the
     * retries is reported for that student ("coba lagi") instead of
     * stopping the rest.
     *
     * @param  iterable<Siswa>  $siswa
     * @return array{berhasil: list<int>, gagal: array<int, string>} student ids placed, and the reason per student id that was not
     */
    public function tempatkanBanyak(User $pelaku, iterable $siswa, Rombel $rombel): array
    {
        $berhasil = [];
        $gagal = [];

        foreach ($siswa as $satu) {
            try {
                $this->tempatkan($pelaku, $satu, $rombel);
                $berhasil[] = (int) $satu->getKey();
            } catch (PelanggaranAturanAkademik|PelanggaranAturanPengguna $exception) {
                $gagal[(int) $satu->getKey()] = $exception->getMessage();
            } catch (Throwable $exception) {
                if (! $this->causedByConcurrencyError($exception)) {
                    throw $exception;
                }

                $gagal[(int) $satu->getKey()] = self::PESAN_COBA_LAGI;
            }
        }

        return ['berhasil' => $berhasil, 'gagal' => $gagal];
    }

    /**
     * Delete a mistaken placement of an active student in the active or a
     * later school year.
     *
     * @throws PelanggaranAturanAkademik
     */
    public function hapus(User $pelaku, PenempatanRombel $penempatan): void
    {
        $this->ensureCanManage($pelaku, $penempatan->siswa);

        DB::transaction(function () use ($pelaku, $penempatan): void {
            $siswa = $this->lockStudent($penempatan->siswa);
            $penempatan = PenempatanRombel::query()->with('rombel.tahunAjaran')->lockForUpdate()->findOrFail($penempatan->getKey());

            if ($siswa->status !== StatusSiswa::Aktif) {
                throw PelanggaranAturanAkademik::penempatanAdalahRiwayat($siswa->user->name);
            }

            $this->ensureNotPast($penempatan->rombel->tahunAjaran);

            $label = AktivitasAkademik::label($penempatan->rombel);
            $penempatan->delete();

            $this->log(AksiAkademik::PenempatanRombelDihapus, $pelaku, $siswa, [
                'attributes' => ['rombel' => null],
                'old' => ['rombel' => $label],
            ]);
        }, self::ATTEMPTS);
    }

    /**
     * For SiswaService::aktifkanKembali(), inside its transaction and after
     * it locked the student row: a returning student counts against the
     * capacity again, so every rombel the student is placed in for the
     * active or a later school year must still have room. Earlier school
     * years are history and are not checked; without an active school year
     * nothing is checked.
     *
     * @throws PelanggaranAturanAkademik
     */
    public function pastikanMuatUntukAktifKembali(Siswa $siswa): void
    {
        $aktif = $this->periodeAkademik->tahunAjaranAktif();

        if ($aktif === null) {
            return;
        }

        $rombelIds = PenempatanRombel::query()
            ->where('siswa_id', $siswa->getKey())
            ->whereHas('tahunAjaran', fn ($query) => $query->whereDate('tanggal_mulai', '>=', $aktif->tanggal_mulai->toDateString()))
            ->sharedLock()
            ->pluck('rombel_id');

        foreach ($rombelIds as $rombelId) {
            $this->ensureRoom($this->lockRombel(Rombel::query()->findOrFail($rombelId)));
        }
    }

    /**
     * The rombel that may be chosen for a placement: those of the active
     * school year and later ones, grouped by school year name, as
     * [school year => [id => "X-1"]]. Empty without an active school year.
     *
     * @return array<string, array<int, string>>
     */
    public function opsiRombel(): array
    {
        $aktif = $this->periodeAkademik->tahunAjaranAktif();

        if ($aktif === null) {
            return [];
        }

        $opsi = [];

        $rombel = Rombel::query()
            ->with('tahunAjaran')
            ->whereHas('tahunAjaran', fn ($query) => $query->whereDate('tanggal_mulai', '>=', $aktif->tanggal_mulai->toDateString()))
            ->join('tahun_ajaran', 'tahun_ajaran.id', '=', 'rombel.tahun_ajaran_id')
            ->orderBy('tahun_ajaran.tanggal_mulai')
            ->urutanTampilan()
            ->select('rombel.*')
            ->get();

        foreach ($rombel as $satu) {
            $opsi[$satu->tahunAjaran->nama][$satu->getKey()] = $satu->nama;
        }

        return $opsi;
    }

    /**
     * For lists: mark in the same query whether each student has a placement
     * hapus() may delete, read with adaPenempatanBisaDihapus().
     *
     * @param  Builder<Siswa>  $query
     * @return Builder<Siswa>
     */
    public function denganPenempatanBisaDihapus(Builder $query): Builder
    {
        $mulaiAktif = $this->periodeAkademik->tahunAjaranAktif()?->tanggal_mulai->toDateString();

        return $query->withExists(['penempatanRombel as punya_penempatan_bisa_dihapus' => fn ($penempatan) => $mulaiAktif === null
            ? $penempatan->whereRaw('1 = 0')
            : $penempatan->whereHas('tahunAjaran', fn ($tahunAjaran) => $tahunAjaran->whereDate('tanggal_mulai', '>=', $mulaiAktif))]);
    }

    /**
     * Whether the student is active and has a placement hapus() may delete.
     * Uses the mark of denganPenempatanBisaDihapus() when loaded.
     */
    public function adaPenempatanBisaDihapus(Siswa $siswa): bool
    {
        if ($siswa->status !== StatusSiswa::Aktif) {
            return false;
        }

        $tanda = $siswa->getAttribute('punya_penempatan_bisa_dihapus');

        return $tanda === null ? $this->penempatanYangBisaDihapus($siswa)->isNotEmpty() : (bool) $tanda;
    }

    /**
     * The placements of $siswa that hapus() may delete: those of the active
     * school year and later ones, while the student is active.
     *
     * @return Collection<int, PenempatanRombel>
     */
    public function penempatanYangBisaDihapus(Siswa $siswa): Collection
    {
        $aktif = $this->periodeAkademik->tahunAjaranAktif();

        if ($aktif === null || $siswa->status !== StatusSiswa::Aktif) {
            return new Collection;
        }

        return PenempatanRombel::query()
            ->with('rombel.tahunAjaran')
            ->where('siswa_id', $siswa->getKey())
            ->whereHas('tahunAjaran', fn ($query) => $query->whereDate('tanggal_mulai', '>=', $aktif->tanggal_mulai->toDateString()))
            ->get();
    }

    private function placementIn(Siswa $siswa, int $tahunAjaranId): ?PenempatanRombel
    {
        return PenempatanRombel::query()
            ->with('rombel.tahunAjaran')
            ->where('siswa_id', $siswa->getKey())
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->lockForUpdate()
            ->first();
    }

    private function ensureActive(Siswa $siswa): void
    {
        if ($siswa->status !== StatusSiswa::Aktif) {
            throw PelanggaranAturanAkademik::siswaTidakAktif($siswa->user->name);
        }
    }

    /**
     * The school year must be the active one or start later.
     */
    private function ensureNotPast(TahunAjaran $tahunAjaran): void
    {
        $aktif = $this->periodeAkademik->tahunAjaranAktifAtauGagal();

        if ($tahunAjaran->tanggal_mulai->lt($aktif->tanggal_mulai)) {
            throw PelanggaranAturanAkademik::tahunAjaranSudahLewat($tahunAjaran->nama);
        }
    }

    /**
     * Must run with the rombel row locked. The count is a locking read.
     */
    private function ensureRoom(Rombel $rombel): void
    {
        if ($rombel->kapasitas !== null && $this->rombelSiswa->jumlahSiswaAktifTerkunci($rombel) >= $rombel->kapasitas) {
            throw PelanggaranAturanAkademik::rombelPenuh($rombel->nama, $rombel->kapasitas);
        }
    }

    private function lockStudent(Siswa $siswa): Siswa
    {
        return Siswa::query()->lockForUpdate()->findOrFail($siswa->getKey());
    }

    private function lockRombel(Rombel $rombel): Rombel
    {
        return Rombel::query()->with('tahunAjaran')->lockForUpdate()->findOrFail($rombel->getKey());
    }

    private function ensureCanManage(User $pelaku, Siswa $siswa): void
    {
        if (! $this->userService->canManage($pelaku, $siswa->user)) {
            throw PelanggaranAturanPengguna::tidakBolehMengelola();
        }
    }

    /**
     * @param  array{attributes: array<string, mixed>, old?: array<string, mixed>}  $changes
     */
    private function log(AksiAkademik $aksi, User $pelaku, Siswa $siswa, array $changes): void
    {
        activity(AktivitasAkademik::LOG_NAME)
            ->causedBy($pelaku)
            ->performedOn($siswa)
            ->event($aksi->value)
            ->withChanges($changes)
            ->log($aksi->label());
    }
}
