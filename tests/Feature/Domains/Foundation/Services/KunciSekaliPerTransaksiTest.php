<?php

namespace Tests\Feature\Domains\Foundation\Services;

use App\Domains\Foundation\Services\KunciSekaliPerTransaksi;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * "Lock every row of a table" runs once per transaction: the mark that the
 * lock is held belongs to the transaction level that took it, follows it
 * down when a savepoint is released, is forgotten when that level is rolled
 * back (ROLLBACK TO SAVEPOINT), and is cleared when the outermost transaction
 * ends. Outside a transaction the lock always runs.
 *
 * No database refresh trait: these tests open and end their own outermost
 * transactions, and lock nothing real.
 */
class KunciSekaliPerTransaksiTest extends TestCase
{
    private int $jumlah = 0;

    public function test_inside_one_transaction_the_lock_runs_once(): void
    {
        DB::transaction(function (): void {
            $this->kunci();
            $this->kunci();
            DB::transaction(fn () => $this->kunci());
        });

        $this->assertSame(1, $this->jumlah);
    }

    public function test_the_mark_is_cleared_when_the_outermost_transaction_commits(): void
    {
        DB::transaction(fn () => $this->kunci());
        DB::transaction(fn () => $this->kunci());

        $this->assertSame(2, $this->jumlah);
    }

    public function test_the_mark_is_cleared_when_the_outermost_transaction_rolls_back(): void
    {
        try {
            DB::transaction(function (): void {
                $this->kunci();

                throw new RuntimeException('batal');
            });
        } catch (RuntimeException) {
            // Rolled back.
        }

        DB::transaction(fn () => $this->kunci());

        $this->assertSame(2, $this->jumlah);
    }

    public function test_a_lock_taken_in_a_savepoint_that_is_rolled_back_is_taken_again(): void
    {
        DB::transaction(function (): void {
            try {
                DB::transaction(function (): void {
                    $this->kunci();

                    throw new RuntimeException('batal');
                });
            } catch (RuntimeException) {
                // ROLLBACK TO SAVEPOINT.
            }

            $this->kunci();
            $this->kunci();
        });

        $this->assertSame(2, $this->jumlah);
    }

    public function test_a_lock_taken_in_a_released_savepoint_stays_held_until_an_outer_rollback(): void
    {
        DB::transaction(function (): void {
            try {
                DB::transaction(function (): void {
                    DB::transaction(fn () => $this->kunci());
                    $this->kunci();

                    throw new RuntimeException('batal');
                });
            } catch (RuntimeException) {
                // The middle level is rolled back: the lock taken below it goes too.
            }

            $this->kunci();
        });

        $this->assertSame(2, $this->jumlah);
    }

    public function test_a_lock_from_a_released_savepoint_survives_the_rollback_of_a_later_sibling(): void
    {
        DB::transaction(function (): void {
            DB::transaction(fn () => $this->kunci());

            try {
                DB::transaction(function (): void {
                    throw new RuntimeException('batal');
                });
            } catch (RuntimeException) {
                // A sibling savepoint rolled back; the lock belongs to the outer level now.
            }

            $this->kunci();
        });

        $this->assertSame(1, $this->jumlah);
    }

    public function test_outside_a_transaction_the_lock_always_runs(): void
    {
        $this->kunci();
        $this->kunci();

        $this->assertSame(2, $this->jumlah);
    }

    public function test_each_key_is_marked_on_its_own(): void
    {
        $lain = 0;

        DB::transaction(function () use (&$lain): void {
            $this->kunci();
            KunciSekaliPerTransaksi::kunci('lain', function () use (&$lain): void {
                $lain++;
            });
            $this->kunci();
        });

        $this->assertSame(1, $this->jumlah);
        $this->assertSame(1, $lain);
    }

    private function kunci(): void
    {
        KunciSekaliPerTransaksi::kunci('uji', function (): void {
            $this->jumlah++;
        });
    }
}
