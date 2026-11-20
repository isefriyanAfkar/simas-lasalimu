<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The guard of DatabaseTerkomit: a test that commits and truncates runs only
 * on a database whose name, as the connected server reports it (SELECT
 * DATABASE()), ends in _test or _test_<number> (php artisan test --parallel).
 * The configuration is not trusted: DB_URL wins over DB_DATABASE.
 */
class PengamanDatabaseTerkomitTest extends TestCase
{
    public function test_the_names_a_test_database_may_have(): void
    {
        foreach (['simas_lasalimu_test' => true, 'simas_lasalimu_test_3' => true, 'simas_lasalimu_test_12' => true,
            'simas_lasalimu' => false, 'simas_lasalimu_test_lama' => false, 'simas_lasalimu_test_' => false, 'test' => false, '' => false] as $nama => $sah) {
            $this->assertSame($sah, DatabaseTerkomitPengujian::namaDatabaseTest((string) $nama), (string) $nama);
        }
    }

    public function test_the_connected_test_database_is_accepted(): void
    {
        DatabaseTerkomitPengujian::pastikanDatabaseTest((string) config('database.default'));

        $this->assertStringEndsWith('_test', (string) DB::selectOne('select database() as nama')->nama);
    }

    public function test_a_db_url_pointing_at_another_database_is_refused_whatever_the_configured_name(): void
    {
        $asal = config('database.connections.'.config('database.default'));
        $url = sprintf(
            '%s://%s:%s@%s:%s/information_schema',
            $asal['driver'], rawurlencode((string) $asal['username']), rawurlencode((string) $asal['password']), $asal['host'], $asal['port'],
        );
        config(['database.connections.percobaan_url' => [...$asal, 'url' => $url, 'database' => 'simas_lasalimu_test']]);
        $pesan = null;

        try {
            DatabaseTerkomitPengujian::pastikanDatabaseTest('percobaan_url');
        } catch (RuntimeException $exception) {
            $pesan = $exception->getMessage();
        } finally {
            DB::purge('percobaan_url');
        }

        $this->assertSame(
            'Test ini meng-commit dan mengosongkan tabel, jadi hanya boleh berjalan di database yang namanya berakhiran _test atau _test_<angka>. Koneksi percobaan_url tersambung ke database "information_schema". Periksa DB_DATABASE dan DB_URL di phpunit.xml.',
            $pesan,
            'Database "information_schema" diterima.',
        );
    }

    public function test_the_guard_runs_before_the_tables_are_truncated(): void
    {
        $asal = config('database.default');
        $koneksi = config('database.connections.'.$asal);
        config(['database.connections.lain' => [...$koneksi, 'database' => 'information_schema']]);
        config(['database.default' => 'lain']);
        $pengujian = new DatabaseTerkomitPengujian('pengujian');
        $pengujian->pasangAplikasi($this->app);
        $pesan = null;

        try {
            $pengujian->sebelumMengosongkan();
        } catch (RuntimeException $exception) {
            $pesan = $exception->getMessage();
        } finally {
            config(['database.default' => $asal]);
            DB::purge('lain');
        }

        $this->assertStringContainsString('tersambung ke database "information_schema"', (string) $pesan, 'Pengosongan dimulai di database selain _test.');
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
