<?php

namespace App\Domains\People\Enums;

use App\Domains\Foundation\Enums\Peran;

enum JenisPtk: string
{
    case Guru = 'guru';
    case Tendik = 'tendik';

    public function label(): string
    {
        return match ($this) {
            self::Guru => 'Guru',
            self::Tendik => 'Tendik',
        };
    }

    /**
     * The staff role the account receives.
     */
    public function peran(): Peran
    {
        return match ($this) {
            self::Guru => Peran::Guru,
            self::Tendik => Peran::Tendik,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $jenis) {
            $options[$jenis->value] = $jenis->label();
        }

        return $options;
    }
}
