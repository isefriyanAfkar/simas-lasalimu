<?php

namespace App\Domains\People\Enums;

/**
 * Staff (kepegawaian) actions recorded explicitly in the activity log
 * (`event` column, log "kepegawaian"). Plain create/update is recorded by the
 * LogsActivity trait.
 */
enum AksiKepegawaian: string
{
    case StatusKepegawaianDinonaktifkan = 'status_kepegawaian_dinonaktifkan';
    case StatusKepegawaianDiaktifkan = 'status_kepegawaian_diaktifkan';
    case PtkStatusDiubah = 'ptk_status_diubah';
    case PtkDiaktifkanKembali = 'ptk_diaktifkan_kembali';

    public function label(): string
    {
        return match ($this) {
            self::StatusKepegawaianDinonaktifkan => 'Status kepegawaian dinonaktifkan',
            self::StatusKepegawaianDiaktifkan => 'Status kepegawaian diaktifkan',
            self::PtkStatusDiubah => 'Status guru/staf diubah',
            self::PtkDiaktifkanKembali => 'Guru/staf diaktifkan kembali',
        };
    }
}
