<?php

namespace App\Domains\Foundation\Enums;

/**
 * The lock rows of KunciIdentitas, in the order they are always taken:
 * the order of the cases.
 */
enum JenisKunciIdentitas: string
{
    case Siswa = 'siswa';
    case WaliMurid = 'wali_murid';
    case Akun = 'akun';
}
