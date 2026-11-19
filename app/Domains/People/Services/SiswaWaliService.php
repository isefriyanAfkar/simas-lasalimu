<?php

namespace App\Domains\People\Services;

use App\Domains\Foundation\Enums\JenisKelamin;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\KeunikanDatabase;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Enums\AksiKesiswaan;
use App\Domains\People\Enums\HubunganWali;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Models\SiswaWali;
use App\Domains\People\Models\WaliMurid;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place that links students and their parents or guardians
 * (siswa_wali).
 *
 * Rules, per student:
 * - a parent is linked at most once
 * - at most one ayah and one ibu; any number of wali
 * - at most one utama (main contact). The first parent added becomes utama,
 *   unless marked as died; then the next living one does
 * - the utama cannot be let go before another utama is chosen, so the only
 *   parent cannot be let go either
 * - a parent marked as died cannot become utama
 * - an ayah is male and an ibu female: an empty gender is filled in from
 *   the relation, a contradiction is a field error
 * The database keeps the first three as well (unique indexes, see the
 * migration); KeunikanDatabase turns a violation that slips past the checks
 * into a field error.
 *
 * tambahkan() links an existing parent (wali "ada", wali_murid_id; one
 * parent may have several children) or creates a new one with an inactive
 * account (wali "baru", see WaliMuridService). Every change is logged
 * explicitly (log "kesiswaan", subject the student), without the NIK.
 *
 * Every write locks the student row first, then the parent row, then reads
 * the student's relations with a lock, so two changes for one student run
 * one after the other.
 */
class SiswaWaliService
{
    public const string PESAN_UTAMA_BERUBAH = 'Wali utama siswa ini baru saja diubah pengguna lain. Muat ulang, lalu coba lagi.';

    private const int ATTEMPTS = 3;

    /**
     * @var array<string, array{string, string}>
     */
    private const array INDEKS_UNIK = [
        'siswa_wali_siswa_id_wali_murid_id_unique' => ['wali_murid_id', 'Wali ini baru saja ditambahkan ke siswa ini oleh pengguna lain. Muat ulang, lalu coba lagi.'],
        'siswa_wali_siswa_id_penanda_ayah_ibu_unique' => ['hubungan', 'Ayah atau ibu siswa ini baru saja ditambahkan pengguna lain. Muat ulang, lalu coba lagi.'],
        'siswa_wali_siswa_id_penanda_utama_unique' => ['wali_murid_id', self::PESAN_UTAMA_BERUBAH],
    ];

    public function __construct(
        private UserService $userService,
        private WaliMuridService $waliMuridService,
    ) {}

    /**
     * @param  array<string, mixed>  $data  wali ("baru" or "ada"), wali_murid_id, hubungan, and for a new parent the account fields and pekerjaan
     *
     * @throws ValidationException
     */
    public function tambahkan(User $pelaku, Siswa $siswa, array $data): SiswaWali
    {
        $this->ensureCanManage($pelaku, $siswa->user);

        return KeunikanDatabase::jaga(self::INDEKS_UNIK, fn (): SiswaWali => DB::transaction(function () use ($pelaku, $siswa, $data): SiswaWali {
            $siswa = $this->lockStudent($siswa);
            $galat = [];
            $mode = $data['wali'] ?? null;
            $hubungan = HubunganWali::tryFrom(is_string($data['hubungan'] ?? null) ? $data['hubungan'] : '');
            $wali = null;

            if (! in_array($mode, ['baru', 'ada'], true)) {
                $galat['wali'] = ['Pilih wali yang sudah ada atau buat wali baru.'];
            }

            if ($hubungan === null) {
                $galat['hubungan'] = ['Pilih hubungan: ayah, ibu, atau wali.'];
            }

            if ($mode === 'ada') {
                [$wali, $pesan] = $this->parentToLink($pelaku, $siswa, $data['wali_murid_id'] ?? null);

                if ($pesan !== null) {
                    $galat['wali_murid_id'] = [$pesan];
                }

                if ($wali !== null && $hubungan !== null && ($pesan = self::bertentangan($wali, $hubungan)) !== null) {
                    $galat['hubungan'] = [$pesan];
                }
            }

            if ($mode === 'baru') {
                $wajib = $hubungan?->jenisKelamin();

                if ($wajib !== null && blank($data['jenis_kelamin'] ?? null)) {
                    $data['jenis_kelamin'] = $wajib->value;
                }

                $galat = [...$galat, ...$this->waliMuridService->galatData($data, null)];

                if ($wajib !== null && ($data['jenis_kelamin'] ?? null) !== $wajib->value && ! isset($galat['jenis_kelamin'])) {
                    $galat['jenis_kelamin'] = [$hubungan === HubunganWali::Ayah ? 'Ayah harus laki-laki.' : 'Ibu harus perempuan.'];
                }
            }

            if ($hubungan !== null && ! isset($galat['hubungan']) && ($pesan = $this->sudahAdaAyahAtauIbu($siswa, $hubungan, null)) !== null) {
                $galat['hubungan'] = [$pesan];
            }

            if ($galat !== [] || $hubungan === null) {
                throw ValidationException::withMessages($galat);
            }

            if ($wali === null) {
                $wali = $this->waliMuridService->buat($pelaku, $data);
                $wali->load('user');
            } else {
                $this->isiJenisKelamin($pelaku, $wali, $hubungan);
            }

            $adaUtama = SiswaWali::query()->where('siswa_id', $siswa->getKey())->where('utama', true)->lockForUpdate()->exists();

            $siswaWali = SiswaWali::create([
                'siswa_id' => $siswa->getKey(),
                'wali_murid_id' => $wali->getKey(),
                'hubungan' => $hubungan,
                'utama' => ! $adaUtama && ! $wali->meninggal,
            ]);

            $this->log(AksiKesiswaan::WaliDitambahkan, $pelaku, $siswa, [
                'attributes' => ['wali' => $wali->user->name, 'hubungan' => $hubungan->value, 'utama' => $siswaWali->utama],
            ]);

            return $siswaWali;
        }, self::ATTEMPTS));
    }

    /**
     * @param  array{hubungan?: mixed}  $data
     *
     * @throws ValidationException
     */
    public function ubahHubungan(User $pelaku, SiswaWali $siswaWali, array $data): SiswaWali
    {
        $this->ensureCanManage($pelaku, $siswaWali->siswa->user);

        return KeunikanDatabase::jaga(self::INDEKS_UNIK, fn (): SiswaWali => DB::transaction(function () use ($pelaku, $siswaWali, $data): SiswaWali {
            $siswa = $this->lockStudent($siswaWali->siswa);
            $siswaWali = $this->lockRelation($siswaWali);
            $wali = $this->lockParent($siswaWali->waliMurid);
            $hubungan = HubunganWali::tryFrom(is_string($data['hubungan'] ?? null) ? $data['hubungan'] : '');

            if ($hubungan === null) {
                throw ValidationException::withMessages(['hubungan' => 'Pilih hubungan: ayah, ibu, atau wali.']);
            }

            if ($hubungan === $siswaWali->hubungan) {
                return $siswaWali;
            }

            $pesan = self::bertentangan($wali, $hubungan) ?? $this->sudahAdaAyahAtauIbu($siswa, $hubungan, $siswaWali);

            if ($pesan !== null) {
                throw ValidationException::withMessages(['hubungan' => $pesan]);
            }

            $lama = $siswaWali->hubungan;
            $siswaWali->update(['hubungan' => $hubungan]);
            $this->isiJenisKelamin($pelaku, $wali, $hubungan);

            $this->log(AksiKesiswaan::HubunganWaliDiubah, $pelaku, $siswa, [
                'attributes' => ['wali' => $wali->user->name, 'hubungan' => $hubungan->value],
                'old' => ['wali' => $wali->user->name, 'hubungan' => $lama->value],
            ]);

            return $siswaWali;
        }, self::ATTEMPTS));
    }

    /**
     * Make $siswaWali the student's only main contact.
     *
     * @throws PelanggaranAturanKesiswaan
     * @throws ValidationException when a concurrent change made another one utama
     */
    public function jadikanUtama(User $pelaku, SiswaWali $siswaWali): SiswaWali
    {
        $this->ensureCanManage($pelaku, $siswaWali->siswa->user);

        return KeunikanDatabase::jaga(self::INDEKS_UNIK, fn (): SiswaWali => DB::transaction(function () use ($pelaku, $siswaWali): SiswaWali {
            $siswa = $this->lockStudent($siswaWali->siswa);
            $siswaWali = $this->lockRelation($siswaWali);
            $wali = $this->lockParent($siswaWali->waliMurid);

            if ($siswaWali->utama) {
                throw PelanggaranAturanKesiswaan::sudahWaliUtama($wali->user->name);
            }

            if ($wali->meninggal) {
                throw PelanggaranAturanKesiswaan::waliMeninggalTidakBolehUtama($wali->user->name);
            }

            $lama = SiswaWali::query()
                ->with('waliMurid.user')
                ->where('siswa_id', $siswa->getKey())
                ->where('utama', true)
                ->lockForUpdate()
                ->first();

            $lama?->update(['utama' => false]);
            $siswaWali->update(['utama' => true]);

            $this->log(AksiKesiswaan::WaliUtamaDiganti, $pelaku, $siswa, [
                'attributes' => ['wali_utama' => $wali->user->name],
                'old' => ['wali_utama' => $lama?->waliMurid->user->name],
            ]);

            return $siswaWali;
        }, self::ATTEMPTS));
    }

    /**
     * Let a parent go from a student. The parent record and account stay
     * (WaliMuridService::hapus() deletes a parent without children).
     *
     * @throws PelanggaranAturanKesiswaan
     */
    public function lepas(User $pelaku, SiswaWali $siswaWali): void
    {
        $this->ensureCanManage($pelaku, $siswaWali->siswa->user);

        DB::transaction(function () use ($pelaku, $siswaWali): void {
            $siswa = $this->lockStudent($siswaWali->siswa);
            $siswaWali = $this->lockRelation($siswaWali);

            if ($siswaWali->utama) {
                throw PelanggaranAturanKesiswaan::waliUtamaTidakBolehDilepas($siswaWali->waliMurid->user->name, $siswa->user->name);
            }

            $siswaWali->delete();

            $this->log(AksiKesiswaan::WaliDilepas, $pelaku, $siswa, [
                'attributes' => [],
                'old' => ['wali' => $siswaWali->waliMurid->user->name, 'hubungan' => $siswaWali->hubungan->value],
            ]);
        }, self::ATTEMPTS);
    }

    /**
     * The existing parent to link, or the reason it cannot be linked. Locks
     * the parent row.
     *
     * @return array{WaliMurid|null, string|null}
     */
    private function parentToLink(User $pelaku, Siswa $siswa, mixed $waliId): array
    {
        if (! is_int($waliId) && ! (is_string($waliId) && ctype_digit($waliId))) {
            return [null, 'Pilih wali murid yang sudah ada.'];
        }

        $wali = WaliMurid::query()->with('user')->lockForUpdate()->find((int) $waliId);

        if ($wali === null) {
            return [null, 'Wali murid tidak ditemukan.'];
        }

        if (! $this->userService->canManage($pelaku, $wali->user)) {
            return [null, PelanggaranAturanPengguna::tidakBolehMengelola()->getMessage()];
        }

        $sudah = SiswaWali::query()
            ->where('siswa_id', $siswa->getKey())
            ->where('wali_murid_id', $wali->getKey())
            ->lockForUpdate()
            ->exists();

        if ($sudah) {
            return [$wali, "{$wali->user->name} sudah menjadi wali murid {$siswa->user->name}."];
        }

        return [$wali, null];
    }

    /**
     * "Budi sudah punya ayah: Ahmad." when the student already has another
     * parent with this single relation. A locking read.
     */
    private function sudahAdaAyahAtauIbu(Siswa $siswa, HubunganWali $hubungan, ?SiswaWali $kecuali): ?string
    {
        if ($hubungan === HubunganWali::Wali) {
            return null;
        }

        $ada = SiswaWali::query()
            ->with('waliMurid.user')
            ->where('siswa_id', $siswa->getKey())
            ->where('hubungan', $hubungan->value)
            ->when($kecuali, fn ($query) => $query->whereKeyNot($kecuali?->getKey()))
            ->lockForUpdate()
            ->first();

        return $ada === null ? null : "{$siswa->user->name} sudah punya ".mb_strtolower($hubungan->label()).": {$ada->waliMurid->user->name}.";
    }

    /**
     * "Siti tercatat perempuan, tidak bisa menjadi ayah." when the parent's
     * stored gender contradicts the relation.
     */
    private static function bertentangan(WaliMurid $wali, HubunganWali $hubungan): ?string
    {
        $wajib = $hubungan->jenisKelamin();
        $tercatat = $wali->user->jenis_kelamin;

        if ($wajib === null || ! $tercatat instanceof JenisKelamin || $tercatat === $wajib) {
            return null;
        }

        return sprintf('%s tercatat %s, tidak bisa menjadi %s.', $wali->user->name, mb_strtolower($tercatat->label()), mb_strtolower($hubungan->label()));
    }

    private function isiJenisKelamin(User $pelaku, WaliMurid $wali, HubunganWali $hubungan): void
    {
        $wajib = $hubungan->jenisKelamin();

        if ($wajib !== null && $wali->user->jenis_kelamin === null) {
            $this->userService->isiJenisKelaminWali($pelaku, $wali->user, $wajib);
        }
    }

    private function lockStudent(Siswa $siswa): Siswa
    {
        return Siswa::query()->with('user')->lockForUpdate()->findOrFail($siswa->getKey());
    }

    private function lockParent(WaliMurid $wali): WaliMurid
    {
        return WaliMurid::query()->with('user')->lockForUpdate()->findOrFail($wali->getKey());
    }

    private function lockRelation(SiswaWali $siswaWali): SiswaWali
    {
        return SiswaWali::query()->with('waliMurid.user')->lockForUpdate()->findOrFail($siswaWali->getKey());
    }

    private function ensureCanManage(User $pelaku, User $user): void
    {
        if (! $this->userService->canManage($pelaku, $user)) {
            throw PelanggaranAturanPengguna::tidakBolehMengelola();
        }
    }

    /**
     * @param  array{attributes: array<string, mixed>, old?: array<string, mixed>}  $changes
     */
    private function log(AksiKesiswaan $aksi, User $pelaku, Siswa $siswa, array $changes): void
    {
        activity(WaliMuridService::LOG_NAME)
            ->causedBy($pelaku)
            ->performedOn($siswa)
            ->event($aksi->value)
            ->withChanges($changes)
            ->log($aksi->label());
    }
}
