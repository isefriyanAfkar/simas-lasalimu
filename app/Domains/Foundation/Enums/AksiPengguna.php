<?php

namespace App\Domains\Foundation\Enums;

/**
 * User management actions recorded in the activity log (`event` column).
 */
enum AksiPengguna: string
{
    case Dibuat = 'dibuat';
    case Diubah = 'diubah';
    case Diaktifkan = 'diaktifkan';
    case Dinonaktifkan = 'dinonaktifkan';
    case KataSandiDiaturUlang = 'kata_sandi_diatur_ulang';
    case RoleBerubah = 'role_berubah';
    case ProfilDiubah = 'profil_diubah';
    case KataSandiDiubahSendiri = 'kata_sandi_diubah_sendiri';
    case NikDitampilkan = 'nik_ditampilkan';

    public function label(): string
    {
        return match ($this) {
            self::Dibuat => 'Pengguna dibuat',
            self::Diubah => 'Pengguna diubah',
            self::Diaktifkan => 'Pengguna diaktifkan',
            self::Dinonaktifkan => 'Pengguna dinonaktifkan',
            self::KataSandiDiaturUlang => 'Kata sandi diatur ulang',
            self::RoleBerubah => 'Role berubah',
            self::ProfilDiubah => 'Profil diubah',
            self::KataSandiDiubahSendiri => 'Kata sandi diubah sendiri',
            self::NikDitampilkan => 'NIK ditampilkan',
        };
    }
}
