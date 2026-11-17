<?php

namespace App\Domains\Foundation\Services;

/**
 * NIK helpers that never store or log the NIK itself.
 *
 * hash() gives the HMAC-SHA256 kept in users.nik_hash, keyed by
 * config('simas.nik_hmac_key') (NIK_HMAC_KEY), which is separate from
 * APP_KEY. Because the NIK column is encrypted, this hash is the only way to
 * search for a NIK or to check that it is unique.
 */
class PenjagaNik
{
    /**
     * The shortest key accepted, in bytes (same strength as APP_KEY).
     */
    private const int PANJANG_KUNCI_MINIMAL = 32;

    /**
     * @throws KunciNikTidakSiap when NIK_HMAC_KEY is empty, broken or too short
     */
    public function hash(string $nik): string
    {
        return hash_hmac('sha256', $nik, $this->kunci());
    }

    /**
     * "7404********0001": the first and last four digits stay readable.
     */
    public static function samarkan(string $nik): string
    {
        return substr($nik, 0, 4).str_repeat('*', max(strlen($nik) - 8, 0)).substr($nik, -4);
    }

    /**
     * Written like APP_KEY: "base64:<base64 bytes>", or the raw bytes.
     */
    private function kunci(): string
    {
        $kunci = config('simas.nik_hmac_key');

        if (! is_string($kunci) || $kunci === '') {
            throw KunciNikTidakSiap::belumDiatur();
        }

        if (str_starts_with($kunci, 'base64:')) {
            $kunci = base64_decode(substr($kunci, 7), true);

            if ($kunci === false) {
                throw KunciNikTidakSiap::tidakValid();
            }
        }

        if (strlen($kunci) < self::PANJANG_KUNCI_MINIMAL) {
            throw KunciNikTidakSiap::tidakValid();
        }

        return $kunci;
    }
}
