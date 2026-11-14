<?php

namespace App\Domains\Foundation\Enums;

enum Peran: string
{
    case SuperAdmin = 'super_admin';
    case Operator = 'operator';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Operator => 'Operator',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $peran) {
            $options[$peran->value] = $peran->label();
        }

        return $options;
    }
}
