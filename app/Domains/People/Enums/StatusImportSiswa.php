<?php

namespace App\Domains\People\Enums;

/**
 * Where a student import stands (ImportSiswaService):
 * menunggu → memeriksa → bergalat or siap; siap → menyimpan → selesai, or
 * back to bergalat when the data changed since the check. dibatalkan and
 * gagal end an import too; gagal means it stopped without a result (see
 * pesan).
 */
enum StatusImportSiswa: string
{
    case Menunggu = 'menunggu';
    case Memeriksa = 'memeriksa';
    case Bergalat = 'bergalat';
    case Siap = 'siap';
    case Menyimpan = 'menyimpan';
    case Selesai = 'selesai';
    case Dibatalkan = 'dibatalkan';
    case Gagal = 'gagal';

    public function label(): string
    {
        return match ($this) {
            self::Menunggu => 'Menunggu diperiksa',
            self::Memeriksa => 'Sedang diperiksa',
            self::Bergalat => 'Ada galat',
            self::Siap => 'Siap disimpan',
            self::Menyimpan => 'Sedang disimpan',
            self::Selesai => 'Tersimpan',
            self::Dibatalkan => 'Dibatalkan',
            self::Gagal => 'Gagal',
        };
    }

    /**
     * The import is over: its file is gone and nothing more happens to it.
     */
    public function selesai(): bool
    {
        return in_array($this, [self::Selesai, self::Dibatalkan, self::Gagal], true);
    }
}
