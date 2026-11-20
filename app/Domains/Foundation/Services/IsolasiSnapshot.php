<?php

namespace App\Domains\Foundation\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Runs work with innodb_snapshot_isolation OFF in this connection's
 * session, and gives the session its value back afterwards, also when the
 * work throws: a queue worker reuses the connection for the next job.
 *
 * Why: from MariaDB 11.6.2 the variable is ON by default. A long
 * transaction that has read rows (its snapshot) then cannot lock or change
 * a row another session committed a change to in the meantime: MariaDB
 * refuses with error 1020 "Record has changed since last read". The student
 * import holds the identity locks (KunciIdentitas) but no lock on the
 * accounts it will change, on purpose, so a password reset or a status
 * change of one of them during the import would make the whole import
 * fail. With the variable OFF the import locks and changes the latest
 * version of that row instead, which keeps the other session's change: the
 * import writes only the fields of its file, never the password or the
 * status. MariaDB before 11.6.2 and MySQL do not have the variable and
 * always behave as OFF; there nothing is set.
 */
class IsolasiSnapshot
{
    protected const string VARIABEL = 'innodb_snapshot_isolation';

    /**
     * @template T
     *
     * @param  Closure(): T  $aksi
     * @return T
     */
    public static function nonaktifkanSelama(Closure $aksi): mixed
    {
        // SHOW takes no bound parameter; the name is a constant of this
        // class, checked to be a plain identifier.
        if (preg_match('/^[a-z_]+$/', static::VARIABEL) !== 1) {
            throw new LogicException('Invalid variable name.');
        }

        $baris = DB::selectOne("SHOW SESSION VARIABLES LIKE '".static::VARIABEL."'");
        $semula = is_object($baris) ? strtoupper((string) ($baris->Value ?? '')) : null;

        if (! in_array($semula, ['ON', 'OFF'], true)) {
            return $aksi();
        }

        DB::statement('SET SESSION '.static::VARIABEL.' = OFF');

        try {
            return $aksi();
        } finally {
            DB::statement('SET SESSION '.static::VARIABEL.' = '.$semula);
        }
    }
}
