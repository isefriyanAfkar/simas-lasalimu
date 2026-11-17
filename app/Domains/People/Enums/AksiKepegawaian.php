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

    public function label(): string
    {
        return match ($this) {
            self::StatusKepegawaianDinonaktifkan => 'Status kepegawaian dinonaktifkan',
            self::StatusKepegawaianDiaktifkan => 'Status kepegawaian diaktifkan',
        };
    }
}
