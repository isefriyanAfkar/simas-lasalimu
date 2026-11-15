<?php

namespace App\Domains\Academic\Services;

use App\Domains\Academic\Models\Semester;
use App\Domains\Academic\Models\TahunAjaran;

/**
 * The one standard way to read the active school year and semester. Every
 * module must use this instead of querying status_aktif itself.
 */
class PeriodeAkademik
{
    public function tahunAjaranAktif(): ?TahunAjaran
    {
        return TahunAjaran::query()->where('status_aktif', true)->first();
    }

    public function semesterAktif(): ?Semester
    {
        return Semester::query()->with('tahunAjaran')->where('status_aktif', true)->first();
    }

    /**
     * @throws PelanggaranAturanAkademik
     */
    public function tahunAjaranAktifAtauGagal(): TahunAjaran
    {
        return $this->tahunAjaranAktif() ?? throw PelanggaranAturanAkademik::belumAdaTahunAjaranAktif();
    }

    /**
     * @throws PelanggaranAturanAkademik
     */
    public function semesterAktifAtauGagal(): Semester
    {
        return $this->semesterAktif() ?? throw PelanggaranAturanAkademik::belumAdaSemesterAktif();
    }
}
