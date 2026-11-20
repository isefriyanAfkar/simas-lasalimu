<?php

namespace Tests\Feature\Domains\Foundation\Services;

use App\Domains\Foundation\Services\IsolasiSnapshot;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The student import runs with innodb_snapshot_isolation OFF in its own
 * session, and gives the session its value back afterwards, also when it
 * fails; a server without the variable runs it unchanged.
 */
class IsolasiSnapshotTest extends TestCase
{
    public function test_the_variable_is_off_while_it_runs_and_given_back_after(): void
    {
        $this->assertNotNull($this->nilai(), 'This MariaDB has innodb_snapshot_isolation.');

        foreach (['ON', 'OFF'] as $semula) {
            DB::statement("SET SESSION innodb_snapshot_isolation = {$semula}");

            $selama = IsolasiSnapshot::nonaktifkanSelama(fn (): ?string => $this->nilai());

            $this->assertSame('OFF', $selama, "semula {$semula}");
            $this->assertSame($semula, $this->nilai());
        }
    }

    public function test_the_value_is_given_back_when_the_work_fails(): void
    {
        DB::statement('SET SESSION innodb_snapshot_isolation = ON');
        $selama = null;

        try {
            IsolasiSnapshot::nonaktifkanSelama(function () use (&$selama): never {
                $selama = $this->nilai();

                throw new RuntimeException('gagal');
            });
            $this->fail('The exception was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('gagal', $exception->getMessage());
        }

        $this->assertSame('OFF', $selama);
        $this->assertSame('ON', $this->nilai());
    }

    public function test_a_server_without_the_variable_runs_the_work_without_setting_anything(): void
    {
        $perintah = [];
        DB::listen(function (QueryExecuted $query) use (&$perintah): void {
            if (str_starts_with(strtolower($query->sql), 'set ')) {
                $perintah[] = $query->sql;
            }
        });

        $hasil = IsolasiSnapshotTanpaVariabel::nonaktifkanSelama(fn (): string => 'jalan');

        $this->assertSame('jalan', $hasil);
        $this->assertSame([], $perintah);
    }

    private function nilai(): ?string
    {
        $baris = DB::selectOne("SHOW SESSION VARIABLES LIKE 'innodb_snapshot_isolation'");

        return $baris?->Value;
    }
}

/**
 * Stands for a server that does not know the variable (MariaDB before
 * 11.6.2, MySQL).
 */
final class IsolasiSnapshotTanpaVariabel extends IsolasiSnapshot
{
    protected const string VARIABEL = 'variabel_yang_tidak_ada_di_server';
}
