<?php

namespace App\Domains\Academic\Services;

use App\Domains\Academic\Enums\Tingkat;
use App\Domains\Academic\Models\PenempatanRombel;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Foundation\Models\User;
use App\Domains\People\Enums\StatusSiswa;
use App\Domains\People\Models\Siswa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The one standard way to read which rombel a student is in during the
 * active school year, and who is in a rombel. Attendance, journals and every
 * other module use this instead of querying penempatan_rombel themselves.
 * The active school year comes from PeriodeAkademik.
 */
class RombelSiswa
{
    public function __construct(private PeriodeAkademik $periodeAkademik) {}

    /**
     * The student's rombel in the active school year, or null when the
     * student has none or no school year is active.
     */
    public function rombelTahunAjaranAktif(Siswa $siswa): ?Rombel
    {
        $aktif = $this->periodeAkademik->tahunAjaranAktif();

        if ($aktif === null) {
            return null;
        }

        return PenempatanRombel::query()
            ->with('rombel.tahunAjaran')
            ->where('siswa_id', $siswa->getKey())
            ->where('tahun_ajaran_id', $aktif->getKey())
            ->first()
            ?->rombel;
    }

    /**
     * The student's rombel in the given school year, or null.
     */
    public function rombelDi(Siswa $siswa, int $tahunAjaranId): ?Rombel
    {
        return PenempatanRombel::query()
            ->with('rombel.tahunAjaran')
            ->where('siswa_id', $siswa->getKey())
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->first()
            ?->rombel;
    }

    /**
     * For list filters: students in $rombelId (any school year; the filter
     * offers only rombel of the active one).
     *
     * @param  Builder<Siswa>  $query
     * @return Builder<Siswa>
     */
    public function diRombel(Builder $query, int $rombelId): Builder
    {
        return $query->whereHas('penempatanRombel', fn ($penempatan) => $penempatan->where('rombel_id', $rombelId));
    }

    /**
     * For list filters: students whose rombel in the active school year has
     * tingkat $tingkat. Without an active school year nobody matches.
     *
     * @param  Builder<Siswa>  $query
     * @return Builder<Siswa>
     */
    public function diTingkatTahunAjaranAktif(Builder $query, Tingkat $tingkat): Builder
    {
        $aktifId = $this->periodeAkademik->tahunAjaranAktif()?->getKey();

        return $query->whereHas('penempatanRombel', fn ($penempatan) => $penempatan
            ->where('tahun_ajaran_id', $aktifId ?? 0)
            ->whereHas('rombel', fn ($rombel) => $rombel->where('tingkat', $tingkat->value)));
    }

    /**
     * The rombel of the active school year, in display order, as id => "X-1",
     * for list filters.
     *
     * @return array<int, string>
     */
    public function opsiRombelTahunAjaranAktif(): array
    {
        $aktif = $this->periodeAkademik->tahunAjaranAktif();

        if ($aktif === null) {
            return [];
        }

        return Rombel::query()
            ->where('tahun_ajaran_id', $aktif->getKey())
            ->urutanTampilan()
            ->get()
            ->mapWithKeys(fn (Rombel $rombel): array => [$rombel->getKey() => $rombel->nama])
            ->all();
    }

    /**
     * For lists: load each student's placement in the active school year
     * (with its rombel) in one query, to be read with rombelTermuat().
     *
     * @param  Builder<Siswa>  $query
     * @return Builder<Siswa>
     */
    public function denganRombelTahunAjaranAktif(Builder $query): Builder
    {
        $aktifId = $this->periodeAkademik->tahunAjaranAktif()?->getKey();

        return $query->with(['penempatanRombel' => fn ($penempatan) => $penempatan
            ->where('tahun_ajaran_id', $aktifId ?? 0)
            ->with('rombel')]);
    }

    /**
     * The rombel loaded by denganRombelTahunAjaranAktif(). Without that
     * loading it falls back to rombelTahunAjaranAktif(), never to an
     * unfiltered list of placements.
     */
    public function rombelTermuat(Siswa $siswa): ?Rombel
    {
        if (! $siswa->relationLoaded('penempatanRombel')) {
            return $this->rombelTahunAjaranAktif($siswa);
        }

        return $siswa->penempatanRombel->first()?->rombel;
    }

    /**
     * Everyone placed in the rombel, students who left included, by name.
     *
     * @return Collection<int, Siswa>
     */
    public function anggota(Rombel $rombel): Collection
    {
        return Siswa::query()
            ->with('user')
            ->whereHas('penempatanRombel', fn ($penempatan) => $penempatan->where('rombel_id', $rombel->getKey()))
            ->orderBy(User::query()->select('name')->whereColumn('users.id', 'siswa.user_id'))
            ->get();
    }

    /**
     * The active students placed in the rombel: the number that counts
     * against its capacity.
     */
    public function jumlahSiswaAktif(Rombel $rombel): int
    {
        return PenempatanRombel::query()
            ->where('rombel_id', $rombel->getKey())
            ->whereHas('siswa', fn ($siswa) => $siswa->where('status', StatusSiswa::Aktif->value))
            ->count();
    }
}
