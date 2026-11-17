<?php

namespace App\Domains\Foundation\Enums;

enum JenisKelamin: string
{
    case LakiLaki = 'laki-laki';
    case Perempuan = 'perempuan';

    public function label(): string
    {
        return match ($this) {
            self::LakiLaki => 'Laki-laki',
            self::Perempuan => 'Perempuan',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $jenisKelamin) {
            $options[$jenisKelamin->value] = $jenisKelamin->label();
        }

        return $options;
    }
}
