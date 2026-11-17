<?php

namespace App\Domains\Foundation\Services;

use RuntimeException;

/**
 * NIK_HMAC_KEY is missing or unusable, so a NIK can be neither saved nor
 * searched. The message never contains the key.
 */
class KunciNikTidakSiap extends RuntimeException
{
    public static function belumDiatur(): self
    {
        return new self('NIK_HMAC_KEY belum diatur.');
    }

    public static function tidakValid(): self
    {
        return new self('NIK_HMAC_KEY tidak valid: gunakan minimal 32 byte, misalnya "base64:" diikuti 32 byte acak.');
    }
}
