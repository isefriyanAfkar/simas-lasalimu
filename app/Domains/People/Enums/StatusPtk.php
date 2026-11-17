<?php

namespace App\Domains\People\Enums;

enum StatusPtk: string
{
    case Aktif = 'aktif';
    case Mutasi = 'mutasi';
    case Pensiun = 'pensiun';
    case Berhenti = 'berhenti';

    public function label(): string
    {
        return match ($this) {
            self::Aktif => 'Aktif',
            self::Mutasi => 'Mutasi',
            self::Pensiun => 'Pensiun',
            self::Berhenti => 'Berhenti',
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
     * The statuses a staff member can leave with (everything but aktif).
     *
     * @return array<string, string>
     */
    public static function opsiKeluar(): array
    {
        return array_diff_key(self::options(), [self::Aktif->value => true]);
    }
}
