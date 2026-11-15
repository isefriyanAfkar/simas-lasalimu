<?php

namespace App\Domains\Academic\Enums;

/**
 * Academic actions recorded explicitly in the activity log (`event` column).
 * Plain create/update/delete is recorded by the LogsActivity trait.
 */
enum AksiAkademik: string
{
    case TahunAjaranDiaktifkan = 'tahun_ajaran_diaktifkan';
    case SemesterDiaktifkan = 'semester_diaktifkan';

    public function label(): string
    {
        return match ($this) {
            self::TahunAjaranDiaktifkan => 'Tahun ajaran diaktifkan',
            self::SemesterDiaktifkan => 'Semester diaktifkan',
        };
    }
}
