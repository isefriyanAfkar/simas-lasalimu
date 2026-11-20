<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * For tests whose data must be committed, so a second connection ("kedua",
 * its own database session) sees it and waits on its locks: concurrency
 * tests. The tables are truncated before and after every test, except the
 * ones whose rows come from migrations.
 *
 * Every test using this trait or the second connection stops before
 * touching any database whose name does not end in _test.
 */
trait DatabaseTerkomit
{
    use DatabaseTruncation;

    public const string KONEKSI_KEDUA = 'kedua';

    /**
     * Rows the migrations insert, which the other tests rely on.
     *
     * @var list<string>
     */
    protected array $exceptTables = ['kunci_identitas', 'status_kepegawaian', 'settings'];

    /**
     * Stops the test unless the database of $koneksi (from the
     * configuration, before any connection is made) ends in _test.
     */
    public static function pastikanDatabaseTest(string $koneksi): void
    {
        $nama = (string) config("database.connections.{$koneksi}.database");

        if (! str_ends_with($nama, '_test')) {
            throw new RuntimeException("Test ini meng-commit dan mengosongkan tabel, jadi hanya boleh berjalan di database yang namanya berakhiran _test. Koneksi {$koneksi} memakai database \"{$nama}\". Periksa DB_DATABASE di phpunit.xml.");
        }
    }

    protected function beforeTruncatingDatabase(): void
    {
        self::pastikanDatabaseTest((string) config('database.default'));
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            DB::connection()->rollBack(0);
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    /**
     * A second connection to the same test database, waiting at most
     * $tungguDetik seconds for a lock.
     */
    protected function koneksiKedua(int $tungguDetik = 1): string
    {
        config(['database.connections.'.self::KONEKSI_KEDUA => config('database.connections.'.config('database.default'))]);
        self::pastikanDatabaseTest(self::KONEKSI_KEDUA);
        DB::connection(self::KONEKSI_KEDUA)->statement("SET SESSION innodb_lock_wait_timeout = {$tungguDetik}");

        return self::KONEKSI_KEDUA;
    }
}
