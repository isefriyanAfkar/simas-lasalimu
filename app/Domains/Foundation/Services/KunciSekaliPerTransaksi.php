<?php

namespace App\Domains\Foundation\Services;

use Closure;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
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
 *
 * Where the marks live: in one instance bound as scoped in the container
 * (AppServiceProvider), never in a static property. A web request is its
 * own PHP process, or gets a fresh scope under Octane, so it starts without
 * marks. A queue worker runs many jobs in one process; the marks must not
 * travel from one job to the next even when a transaction ended without its
 * events (a broken connection, a killed statement). Two layers clear them,
 * independent of the transaction events:
 * - the worker forgets the scoped instances before every job it picks up
 *   (queue:work in daemon mode, see QueueServiceProvider::registerWorker);
 * - every job start and end (processing, processed, exception, failed)
 *   forgets the marks, also for queue:work --once and the sync queue.
 */
final class KunciSekaliPerTransaksi
{
    /**
     * Connection name => [key => transaction level that holds the lock].
     *
     * @var array<string, array<string, int>>
     */
    private array $dipegang = [];

    public static function kunci(string $kunci, Closure $kunciSemua): void
    {
        $koneksi = DB::connection();
        $nama = (string) $koneksi->getName();
        $level = $koneksi->transactionLevel();

        if ($level === 0) {
            $kunciSemua();

            return;
        }

        $penanda = self::penanda();

        if (isset($penanda->dipegang[$nama][$kunci])) {
            return;
        }

        $kunciSemua();
        $penanda->dipegang[$nama][$kunci] = $level;
    }

    /**
     * Forget every mark of every connection.
     */
    public static function lupakanSemua(): void
    {
        self::penanda()->dipegang = [];
    }

    /**
     * Follow every transaction of every connection, and every queued job.
     * Called once from AppServiceProvider::boot().
     */
    public static function daftarkan(): void
    {
        Event::listen(TransactionBeginning::class, function (TransactionBeginning $event): void {
            if ($event->connection->transactionLevel() === 1) {
                unset(self::penanda()->dipegang[$event->connectionName]);
            }
        });

        Event::listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
            self::penanda()->turunkan($event->connectionName, $event->connection->transactionLevel());
        });

        Event::listen(TransactionRolledBack::class, function (TransactionRolledBack $event): void {
            self::penanda()->lupakanDiAtas($event->connectionName, $event->connection->transactionLevel());
        });

        Event::listen(
            [JobProcessing::class, JobProcessed::class, JobExceptionOccurred::class, JobFailed::class],
            fn (): null => self::lupakanSemua(),
        );
    }

    private static function penanda(): self
    {
        return app(self::class);
    }

    /**
     * A level was committed: its locks now belong to the level below, or end
     * with the outermost transaction.
     */
    private function turunkan(string $koneksi, int $levelBaru): void
    {
        if ($levelBaru === 0) {
            unset($this->dipegang[$koneksi]);

            return;
        }

        foreach ($this->dipegang[$koneksi] ?? [] as $kunci => $level) {
            $this->dipegang[$koneksi][$kunci] = min($level, $levelBaru);
        }
    }

    /**
     * Rolled back to $levelBaru: marks taken above it are forgotten.
     */
    private function lupakanDiAtas(string $koneksi, int $levelBaru): void
    {
        foreach ($this->dipegang[$koneksi] ?? [] as $kunci => $level) {
            if ($level > $levelBaru) {
                unset($this->dipegang[$koneksi][$kunci]);
            }
        }
    }
}
