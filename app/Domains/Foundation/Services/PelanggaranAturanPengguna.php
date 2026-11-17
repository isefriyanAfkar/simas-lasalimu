<?php

namespace App\Domains\Foundation\Services;

use RuntimeException;

/**
 * Thrown by UserService when an operation breaks a user management rule.
 * The message is safe to show to the person performing the operation.
 */
class PelanggaranAturanPengguna extends RuntimeException
{
    public static function menonaktifkanDiriSendiri(): self
    {
        return new self('Anda tidak bisa menonaktifkan akun Anda sendiri.');
    }

    public static function superAdminAktifTerakhir(): self
    {
        return new self('Super admin aktif terakhir tidak boleh dinonaktifkan atau kehilangan role super_admin.');
    }

    public static function tidakBolehMengelola(): self
    {
        return new self('Anda tidak boleh mengubah pengguna ini.');
    }

    public static function tidakBolehMemberiPeran(string $peran): self
    {
        return new self("Anda tidak boleh memberi role {$peran}.");
    }

    public static function tidakBolehMencabutPeran(string $peran): self
    {
        return new self("Anda tidak boleh mencabut role {$peran}.");
    }

    public static function nikKosong(): self
    {
        return new self('Pengguna ini belum punya NIK.');
    }
}
