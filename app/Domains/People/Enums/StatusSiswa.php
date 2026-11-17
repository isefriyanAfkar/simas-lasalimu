<?php

namespace App\Domains\People\Enums;

enum StatusSiswa: string
{
    case Aktif = 'aktif';
    case Lulus = 'lulus';
    case MutasiKeluar = 'mutasi_keluar';
    case Keluar = 'keluar';

    public function label(): string
    {
        return match ($this) {
            self::Aktif => 'Aktif',
            self::Lulus => 'Lulus',
            self::MutasiKeluar => 'Mutasi keluar',
            self::Keluar => 'Keluar',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }

    /**
     * The statuses a student can leave with (everything but aktif).
     *
     * @return array<string, string>
     */
    public static function opsiKeluar(): array
    {
        return array_diff_key(self::options(), [self::Aktif->value => true]);
    }
}
