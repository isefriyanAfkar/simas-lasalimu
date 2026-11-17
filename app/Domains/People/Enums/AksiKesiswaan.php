<?php

namespace App\Domains\People\Enums;

/**
 * Student (kesiswaan) actions recorded explicitly in the activity log
 * (`event` column, log "kesiswaan"). Plain create/update is recorded by the
 * LogsActivity trait.
 */
enum AksiKesiswaan: string
{
    case SiswaStatusDiubah = 'siswa_status_diubah';
    case SiswaDiaktifkanKembali = 'siswa_diaktifkan_kembali';

    public function label(): string
    {
        return match ($this) {
            self::SiswaStatusDiubah => 'Status siswa diubah',
            self::SiswaDiaktifkanKembali => 'Siswa diaktifkan kembali',
        };
    }
}
