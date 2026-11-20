<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The guard of DatabaseTerkomit: a test that commits and truncates never
 * runs against a database whose name does not end in _test, and stops
 * before connecting to it.
 */
class PengamanDatabaseTerkomitTest extends TestCase
{
    public function test_a_database_not_ending_in_test_is_refused_before_connecting(): void
    {
        foreach (['simas_lasalimu', 'simas_lasalimu_test_lama', 'test', ''] as $nama) {
            $koneksi = 'percobaan_'.md5($nama);
            config(['database.connections.'.$koneksi => [...config('database.connections.'.config('database.default')), 'database' => $nama]]);

            $pesan = null;

            try {
                DatabaseTerkomitPengujian::pastikanDatabaseTest($koneksi);
            } catch (RuntimeException $exception) {
                $pesan = $exception->getMessage();
            }

            $this->assertSame(
                "Test ini meng-commit dan mengosongkan tabel, jadi hanya boleh berjalan di database yang namanya berakhiran _test. Koneksi {$koneksi} memakai database \"{$nama}\". Periksa DB_DATABASE di phpunit.xml.",
                $pesan,
                "Database \"{$nama}\" diterima.",
            );
            $this->assertArrayNotHasKey($koneksi, DB::getConnections());
        }
    }

    public function test_a_database_ending_in_test_is_accepted(): void
    {
        config(['database.connections.percobaan' => [...config('database.connections.'.config('database.default')), 'database' => 'simas_lasalimu_test']]);

        DatabaseTerkomitPengujian::pastikanDatabaseTest('percobaan');

        $this->assertSame('simas_lasalimu_test', config('database.connections.'.config('database.default').'.database'));
    }

    public function test_the_guard_runs_before_the_tables_are_truncated(): void
    {
        $pengujian = new DatabaseTerkomitPengujian('pengujian');
        $pengujian->pasangAplikasi($this->app);
        config(['database.connections.'.config('database.default').'.database' => 'simas_lasalimu']);

        $pesan = null;

        try {
            $pengujian->sebelumMengosongkan();
        } catch (RuntimeException $exception) {
            $pesan = $exception->getMessage();
        } finally {
            config(['database.connections.'.config('database.default').'.database' => 'simas_lasalimu_test']);
        }

        $this->assertStringContainsString('memakai database "simas_lasalimu"', (string) $pesan, 'Pengosongan dimulai di database selain _test.');
    }
}

/**
 * Exposes the guard of DatabaseTerkomit without running a test with it.
 */
final class DatabaseTerkomitPengujian extends TestCase
{
    use DatabaseTerkomit;

    public function pasangAplikasi(mixed $app): void
    {
        $this->app = $app;
    }

    public function sebelumMengosongkan(): void
    {
        $this->beforeTruncatingDatabase();
    }
}
