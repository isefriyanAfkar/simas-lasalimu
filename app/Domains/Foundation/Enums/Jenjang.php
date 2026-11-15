<?php

namespace App\Domains\Foundation\Enums;

enum Jenjang: string
{
    case Sd = 'SD';
    case Smp = 'SMP';
    case Sma = 'SMA';
    case Smk = 'SMK';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $jenjang) {
            $options[$jenjang->value] = $jenjang->value;
        }

        return $options;
    }
}
