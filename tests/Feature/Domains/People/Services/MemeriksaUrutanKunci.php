<?php

namespace Tests\Feature\Domains\People\Services;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Checks the lock order of a path through its SQL (one PHP thread cannot
 * stage two real transactions): the locking reads, and the UPDATE and
 * DELETE of siswa, wali_murid, users and siswa_wali rows (a written row is
 * held until the end of the transaction), in the order they run.
 *
 * The order: the identity lock rows (KunciIdentitas) siswa → wali_murid →
 * akun, then S (siswa rows) → W (wali_murid rows) → users rows → R
 * (siswa_wali rows). An identity lock is never taken for the first time
 * after a data row, siswa, wali_murid and users are never locked whole, and
 * no step comes for the first time after a later one. Locking again what
 * the transaction holds is no new step.
 */
trait MemeriksaUrutanKunci
{
    /**
     * @var array<string, int>
     */
    private static array $urutanKunci = [
        'kunci:siswa' => 0,
        'kunci:wali_murid' => 1,
        'kunci:akun' => 2,
        'siswa' => 3,
        'wali_murid' => 4,
        'users' => 5,
        'siswa_wali' => 6,
    ];

    /**
     * The first lock of each step, in order, must be $expected.
     *
     * @param  list<string>  $expected
     */
    private function assertLockOrder(array $expected, Closure $operation): void
    {
        $kunci = $this->kunciSelama($operation);
        $teks = 'Locks: '.implode(' → ', $kunci);
        $pertama = [];
        $tertinggi = -1;
        $adaBarisData = false;

        foreach ($kunci as $nama) {
            $this->assertStringNotContainsString('(semua)', $nama, "A whole table is locked. {$teks}");

            $identitas = str_starts_with($nama, 'kunci:');
            $this->assertFalse($identitas && $adaBarisData && ! in_array($nama, $pertama, true), "{$nama} is taken after a data row. {$teks}");
            $adaBarisData = $adaBarisData || ! $identitas;

            if (! array_key_exists($nama, self::$urutanKunci) || in_array($nama, $pertama, true)) {
                continue;
            }

            $this->assertGreaterThanOrEqual($tertinggi, self::$urutanKunci[$nama], "{$nama} is locked after a later step. {$teks}");
            $tertinggi = self::$urutanKunci[$nama];
            $pertama[] = $nama;
        }

        $this->assertSame($expected, $pertama, $teks);
    }

    /**
     * Only the identity locks of the path, which must come before any other
     * lock; the data rows it locks are not checked.
     *
     * @param  list<string>  $expected  jenis of KunciIdentitas, in order
     */
    private function assertIdentityLocks(array $expected, Closure $operation): void
    {
        $kunci = $this->kunciSelama($operation);
        $teks = 'Locks: '.implode(' → ', $kunci);
        $identitas = [];
        $adaBarisData = false;

        foreach ($kunci as $nama) {
            $this->assertStringNotContainsString('(semua)', $nama, "A whole table is locked. {$teks}");

            if (! str_starts_with($nama, 'kunci:')) {
                $adaBarisData = true;

                continue;
            }

            $jenis = substr($nama, 6);

            if (! in_array($jenis, $identitas, true)) {
                $this->assertFalse($adaBarisData, "{$nama} is taken after a data row. {$teks}");
                $identitas[] = $jenis;
            }
        }

        $this->assertSame($expected, $identitas, $teks);
    }

    /**
     * @return list<string>
     */
    private function kunciSelama(Closure $operation): array
    {
        /** @var list<string> $kunci */
        $kunci = [];
        $merekam = true;

        /** @var array<string, list<int>> $dibuat */
        $dibuat = [];

        // A row this operation created is seen by no other transaction, so
        // writing it again can wait on nobody.
        Event::listen('eloquent.created: *', function (string $event, array $model) use (&$dibuat, &$merekam): void {
            if ($merekam && ($model[0] ?? null) instanceof Model) {
                $dibuat[$model[0]->getTable()][] = (int) $model[0]->getKey();
            }
        });

        DB::listen(function (QueryExecuted $query) use (&$kunci, &$merekam, &$dibuat): void {
            if (! $merekam) {
                return;
            }

            // A write holds its row until the end of the transaction too.
            if (preg_match('/^(?:update|delete from) `(siswa|wali_murid|users|siswa_wali)`/', $query->sql, $cocok) === 1) {
                $barisBaru = preg_match('/ where `id` = \?$/', $query->sql) === 1 && in_array((int) end($query->bindings), $dibuat[$cocok[1]] ?? [], true);

                if (! $barisBaru) {
                    $kunci[] = $cocok[1];
                }

                return;
            }

            if (preg_match('/(for update|lock in share mode)\s*$/i', $query->sql) !== 1 || preg_match('/\bfrom `(\w+)`/', $query->sql, $cocok) !== 1) {
                return;
            }

            $kunci[] = match (true) {
                $cocok[1] === 'kunci_identitas' => 'kunci:'.$query->bindings[0],
                in_array($cocok[1], ['users', 'siswa', 'wali_murid'], true) && ! str_contains($query->sql, ' where ') => $cocok[1].'(semua)',
                default => $cocok[1],
            };
        });

        $operation();
        $merekam = false;

        return $kunci;
    }
}
