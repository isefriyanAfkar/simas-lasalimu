<?php

namespace App\Domains\People\Services;

use RuntimeException;

/**
 * Thrown when a student operation breaks a rule that is not tied to a form
 * field. The message is safe to show to the user.
 */
class PelanggaranAturanKesiswaan extends RuntimeException
{
    public static function sudahTidakAktif(): self
    {
        return new self('Siswa ini sudah tidak aktif. Aktifkan kembali dulu bila statusnya perlu diubah.');
    }

    public static function sudahAktif(): self
    {
        return new self('Siswa ini masih aktif.');
    }
}
