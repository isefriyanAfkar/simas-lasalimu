<?php

namespace App\Domains\Foundation\Services;

use App\Domains\Foundation\Enums\JenisKunciIdentitas;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One lock row per kind of identity (table kunci_identitas). A write of
 * that identity locks its row first, so such writes queue behind each
 * other, while the data tables are never locked whole: logging in, logging
 * out, the remember_token, a password rehash, and every change of a user
 * row that is not an identity write never wait for them.
 *
 * - akun: username, email, NIK and photo of an account must stay unique;
 *   every account save that checks them (UserService::validateAccountData,
 *   createSuperAdminFromSystem).
 * - wali_murid: a parent is found by NIK or by name and phone number (no
 *   unique index); every write that creates a parent, changes one, or
 *   locks parent rows.
 * - siswa: NIS and NISN must stay unique; every write of a student's
 *   identity or membership.
 *
 * Lock order, of the whole application:
 * siswa → wali_murid → akun → S (siswa rows) → W (wali_murid rows) → users
 * rows → R (siswa_wali rows). The identity rows always come first, before
 * any data row, and always in this order (kunci() sorts them); a
 * transaction takes every one it needs at its start. A path that locks
 * siswa or wali_murid rows takes the matching identity row even when it
 * writes no identity: a student import holds all three for its whole
 * transaction and locks rows in any order, so a path that took a siswa row
 * first could wait on the import while the import waits on it.
 *
 * The limit of the siswa lock: it is taken only by writes of student
 * identity and membership, that is the tables siswa, wali_murid,
 * siswa_wali, penempatan_rombel and the accounts of students and parents.
 * A module that writes data about students in other tables (presensi,
 * jurnal, e-poin, perpustakaan, permohonan) must never take any identity
 * lock: it would wait for every import and every student edit. When it
 * needs the members of a rombel, it reads them through RombelSiswa,
 * without an identity lock.
 *
 * Locking a row again that the transaction already holds is one primary
 * key lookup, so a service called many times in one transaction (an
 * import) takes it every time; nothing is remembered between calls.
 */
final class KunciIdentitas
{
    /**
     * Must run inside a transaction: the rows stay locked until it ends.
     *
     * @throws LogicException outside a transaction, or when a lock row is missing
     */
    public static function kunci(JenisKunciIdentitas ...$jenis): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('KunciIdentitas must run inside a transaction.');
        }

        foreach (JenisKunciIdentitas::cases() as $kasus) {
            if (! in_array($kasus, $jenis, true)) {
                continue;
            }

            // One statement per row: a single SELECT would lock in index
            // order (akun first), not in the lock order.
            $ada = DB::table('kunci_identitas')->where('jenis', $kasus->value)->lockForUpdate()->value('jenis');

            if ($ada === null) {
                throw new LogicException("The lock row \"{$kasus->value}\" is missing from kunci_identitas. Run the migrations.");
            }
        }
    }
}
