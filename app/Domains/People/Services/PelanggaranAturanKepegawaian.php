<?php

namespace App\Domains\People\Services;

use RuntimeException;

/**
 * Thrown when a staff operation breaks a rule that is not tied to a form
 * field. The message is safe to show to the user.
 */
class PelanggaranAturanKepegawaian extends RuntimeException
{
    public static function sudahTidakAktif(): self
    {
        return new self('Guru/staf ini sudah tidak aktif. Aktifkan kembali dulu bila statusnya perlu diubah.');
    }

    public static function sudahAktif(): self
    {
        return new self('Guru/staf ini masih aktif.');
    }
}
