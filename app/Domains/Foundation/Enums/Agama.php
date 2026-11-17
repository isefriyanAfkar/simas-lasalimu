<?php

namespace App\Domains\Foundation\Enums;

enum Agama: string
{
    case Islam = 'Islam';
    case Kristen = 'Kristen';
    case Katolik = 'Katolik';
    case Hindu = 'Hindu';
    case Buddha = 'Buddha';
    case Konghucu = 'Konghucu';
    case Kepercayaan = 'Kepercayaan';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $agama) {
            $options[$agama->value] = $agama->value;
        }

        return $options;
    }
}
