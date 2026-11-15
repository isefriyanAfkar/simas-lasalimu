<?php

namespace App\Domains\Foundation\Settings;

use App\Domains\Foundation\Enums\Jenjang;
use Spatie\LaravelSettings\Settings;

/**
 * School identity. Written only through PengaturanSekolahService.
 * Every property is null until the settings are saved for the first time.
 */
class PengaturanSekolah extends Settings
{
    public ?string $nama_sekolah;

    public ?string $npsn;

    public ?Jenjang $jenjang;

    public ?string $alamat;

    public ?string $telepon;

    public ?string $email;

    /**
     * Path of the logo on the public disk.
     */
    public ?string $logo;

    public static function group(): string
    {
        return 'sekolah';
    }
}
