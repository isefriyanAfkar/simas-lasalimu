<?php

namespace App\Domains\Foundation\Services;

use Closure;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Runs a "lock every row of a table" at most once per transaction.
 *
 * Services lock a whole table before a uniqueness check (UserService locks
 * all users, SiswaService all students). When many saves run inside one
 * transaction, as an import does, repeating that scan on a growing table
 * makes the work grow with the square of the rows. The locks are held until
 * the transaction ends, so taking them again changes nothing; only the scan
 * is saved.
 *
 * The mark "this lock is held" belongs to the transaction level that took
 * it:
 * - releasing a savepoint (an inner DB::transaction commits) moves the mark
 *   down to the level that now holds the work;
 * - rolling back to a savepoint (ROLLBACK TO SAVEPOINT) forgets every mark
 *   taken above that level, so the lock is taken again even though InnoDB
 *   may still hold it: never trusting a lock that may be gone;
 * - the outermost transaction ending, by commit or rollback, and a new
 *   outermost transaction beginning, forget every mark of that connection.
 * Outside a transaction the lock always runs: autocommit releases it at once.
 */
final class KunciSekaliPerTransaksi
{
    /**
     * Connection name => [key => transaction level that holds the lock].
     *
     * @var array<string, array<string, int>>
     */
    private static array $dipegang = [];

    public static function kunci(string $kunci, Closure $kunciSemua): void
    {
        $koneksi = DB::connection();
        $nama = (string) $koneksi->getName();
        $level = $koneksi->transactionLevel();

        if ($level === 0) {
            $kunciSemua();

            return;
        }

        if (isset(self::$dipegang[$nama][$kunci])) {
            return;
        }

        $kunciSemua();
        self::$dipegang[$nama][$kunci] = $level;
    }

    /**
     * Forget every mark. For tests only: RefreshDatabase opens and rolls
     * back the transaction around each test with the event dispatcher
     * switched off, so the marks of one test would otherwise reach the next.
     */
    public static function lupakanSemua(): void
    {
        self::$dipegang = [];
    }

    /**
     * Follow every transaction of every connection. Called once from
     * AppServiceProvider::boot().
     */
    public static function daftarkan(): void
    {
        Event::listen(TransactionBeginning::class, function (TransactionBeginning $event): void {
            if ($event->connection->transactionLevel() === 1) {
                unset(self::$dipegang[$event->connectionName]);
            }
        });

        Event::listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
            self::turunkan($event->connectionName, $event->connection->transactionLevel());
        });

        Event::listen(TransactionRolledBack::class, function (TransactionRolledBack $event): void {
            self::lupakanDiAtas($event->connectionName, $event->connection->transactionLevel());
        });
    }

    /**
     * A level was committed: its locks now belong to the level below, or end
     * with the outermost transaction.
     */
    private static function turunkan(string $koneksi, int $levelBaru): void
    {
        if ($levelBaru === 0) {
            unset(self::$dipegang[$koneksi]);

            return;
        }

        foreach (self::$dipegang[$koneksi] ?? [] as $kunci => $level) {
            self::$dipegang[$koneksi][$kunci] = min($level, $levelBaru);
        }
    }

    /**
     * Rolled back to $levelBaru: marks taken above it are forgotten.
     */
    private static function lupakanDiAtas(string $koneksi, int $levelBaru): void
    {
        foreach (self::$dipegang[$koneksi] ?? [] as $kunci => $level) {
            if ($level > $levelBaru) {
                unset(self::$dipegang[$koneksi][$kunci]);
            }
        }
    }
}
