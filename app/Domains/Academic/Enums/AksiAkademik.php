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
    case MataPelajaranDinonaktifkan = 'mata_pelajaran_dinonaktifkan';
    case MataPelajaranDiaktifkan = 'mata_pelajaran_diaktifkan';
    case SiswaDitempatkan = 'siswa_ditempatkan';
    case SiswaDipindahRombel = 'siswa_dipindah_rombel';
    case PenempatanRombelDihapus = 'penempatan_rombel_dihapus';

    public function label(): string
    {
        return match ($this) {
            self::TahunAjaranDiaktifkan => 'Tahun ajaran diaktifkan',
            self::SemesterDiaktifkan => 'Semester diaktifkan',
            self::MataPelajaranDinonaktifkan => 'Mata pelajaran dinonaktifkan',
            self::MataPelajaranDiaktifkan => 'Mata pelajaran diaktifkan',
            self::SiswaDitempatkan => 'Siswa ditempatkan di rombel',
            self::SiswaDipindahRombel => 'Siswa dipindah rombel',
            self::PenempatanRombelDihapus => 'Penempatan rombel dihapus',
        };
    }
}
