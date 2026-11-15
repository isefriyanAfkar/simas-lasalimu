<?php

namespace App\Domains\Foundation\Enums;

/**
 * Settings actions recorded in the activity log (`event` column).
 */
enum AksiPengaturan: string
{
    case SekolahDiubah = 'pengaturan_sekolah_diubah';

    public function label(): string
    {
        return match ($this) {
            self::SekolahDiubah => 'Pengaturan sekolah diubah',
        };
    }
}
