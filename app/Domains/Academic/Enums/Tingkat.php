<?php

namespace App\Domains\Academic\Enums;

use App\Domains\Foundation\Enums\Jenjang;

/**
 * Grade level 1 to 12, stored as its number and shown in Roman numerals.
 */
enum Tingkat: int
{
    case I = 1;
    case II = 2;
    case III = 3;
    case IV = 4;
    case V = 5;
    case VI = 6;
    case VII = 7;
    case VIII = 8;
    case IX = 9;
    case X = 10;
    case XI = 11;
    case XII = 12;

    public function label(): string
    {
        return $this->name;
    }

    /**
     * The grade levels a school of this level teaches: SD 1-6, SMP 7-9,
     * SMA and SMK 10-12. Kept here, not on Jenjang, so Foundation does not
     * depend on Academic.
     *
     * @return list<Tingkat>
     */
    public static function untukJenjang(Jenjang $jenjang): array
    {
        [$dari, $sampai] = match ($jenjang) {
            Jenjang::Sd => [1, 6],
            Jenjang::Smp => [7, 9],
            Jenjang::Sma, Jenjang::Smk => [10, 12],
        };

        return array_map(self::from(...), range($dari, $sampai));
    }

    /**
     * @return array<int, string> value => Roman numeral
     */
    public static function optionsUntukJenjang(?Jenjang $jenjang): array
    {
        if ($jenjang === null) {
            return [];
        }

        $options = [];

        foreach (self::untukJenjang($jenjang) as $tingkat) {
            $options[$tingkat->value] = $tingkat->label();
        }

        return $options;
    }
}
