<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Laravel (checked on 13.42.0, ConcurrencyErrorDetector) counts these
 * errors as concurrency errors, so a service transaction with attempts runs
 * again instead of failing. Without a test transaction around it: Laravel
 * retries only the outermost transaction (a nested one rethrows a
 * DeadlockException to it).
 */
class PercobaanUlangKonkurensiTest extends TestCase
{
    /**
     * @return array<string, array{int, string}>
     */
    public static function galatKonkurensi(): array
    {
        return [
            '1213 deadlock' => [1213, 'Deadlock found when trying to get lock; try restarting transaction'],
            '1205 lock wait timeout' => [1205, 'Lock wait timeout exceeded; try restarting transaction'],
            '1020 record changed' => [1020, "Record has changed since last read in table 'users'; try restarting transaction"],
        ];
    }

    #[DataProvider('galatKonkurensi')]
    public function test_a_transaction_with_attempts_runs_again(int $kode, string $pesan): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $percobaan = 0;

        $hasil = DB::transaction(function () use (&$percobaan, $kode, $pesan): string {
            $percobaan++;

            if ($percobaan === 1) {
                $pdo = new PDOException("SQLSTATE[HY000]: General error: {$kode} {$pesan}");
                $pdo->errorInfo = ['HY000', $kode, $pesan];

                throw new QueryException('mariadb', 'update `users` set `alamat` = ?', ['x'], $pdo);
            }

            return 'tersimpan';
        }, 3);

        $this->assertSame([2, 'tersimpan'], [$percobaan, $hasil]);
    }
}
