<?php

namespace App\Domains\Foundation\Enums;

/**
 * Two kinds of role:
 * - panel roles (super_admin, operator) open the admin panel and are managed
 *   in Kelola Pengguna, at most one per user;
 * - staff roles (guru, tendik) follow the staff record (PTK), never open the
 *   admin panel, and are never touched by Kelola Pengguna;
 * - the student role (siswa) follows the student record, never opens the
 *   admin panel, and is never touched by Kelola Pengguna. Students will sign
 *   in later through a panel of their own, with the NIS as username.
 */
enum Peran: string
{
    case SuperAdmin = 'super_admin';
    case Operator = 'operator';
    case Guru = 'guru';
    case Tendik = 'tendik';
    case Siswa = 'siswa';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Operator => 'Operator',
            self::Guru => 'Guru',
            self::Tendik => 'Tendik',
            self::Siswa => 'Siswa',
        };
    }

    /**
     * Whether the role opens the admin panel.
     */
    public function aksesPanel(): bool
    {
        return match ($this) {
            self::SuperAdmin, self::Operator => true,
            self::Guru, self::Tendik, self::Siswa => false,
        };
    }

    /**
     * @return list<self>
     */
    public static function peranPanel(): array
    {
        return array_values(array_filter(self::cases(), fn (self $peran): bool => $peran->aksesPanel()));
    }

    /**
     * @return list<string>
     */
    public static function nilaiPanel(): array
    {
        return array_map(fn (self $peran): string => $peran->value, self::peranPanel());
    }

    /**
     * The roles that follow a staff record (PTK). Listed explicitly, so a
     * future role without panel access (for example a parent role) is never
     * swapped by the staff screen.
     *
     * @return list<self>
     */
    public static function peranStaf(): array
    {
        return [self::Guru, self::Tendik];
    }

    /**
     * Panel roles only, for the role field of Kelola Pengguna.
     *
     * @return array<string, string>
     */
    public static function opsiPanel(): array
    {
        $options = [];

        foreach (self::peranPanel() as $peran) {
            $options[$peran->value] = $peran->label();
        }

        return $options;
    }
}
