<?php

namespace App\Domains\People\Enums;

use App\Domains\Foundation\Enums\JenisKelamin;

/**
 * How a parent or guardian relates to a student. A student has at most one
 * ayah and one ibu; any number of wali.
 */
enum HubunganWali: string
{
    case Ayah = 'ayah';
    case Ibu = 'ibu';
    case Wali = 'wali';

    public function label(): string
    {
        return match ($this) {
            self::Ayah => 'Ayah',
            self::Ibu => 'Ibu',
            self::Wali => 'Wali',
        };
    }

    /**
     * The gender this relation implies: an ayah is always male and an ibu
     * always female; a wali may be either.
     */
    public function jenisKelamin(): ?JenisKelamin
    {
        return match ($this) {
            self::Ayah => JenisKelamin::LakiLaki,
            self::Ibu => JenisKelamin::Perempuan,
            self::Wali => null,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $hubungan) {
            $options[$hubungan->value] = $hubungan->label();
        }

        return $options;
    }
}
