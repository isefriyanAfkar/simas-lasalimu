<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The second connection of DatabaseTerkomit opens a second session on the
 * very database the test runs on: simas_lasalimu_test, or the database of
 * its own process when the tests run in parallel.
 */
class KoneksiKeduaTest extends TestCase
{
    use DatabaseTerkomit;

    public function test_the_second_connection_uses_the_database_of_this_test_process(): void
    {
        $utama = (string) DB::selectOne('select database() as nama')->nama;
        $kedua = (string) DB::connection($this->koneksiKedua())->selectOne('select database() as nama')->nama;

        $this->assertSame($utama, $kedua);
        $this->assertTrue(self::namaDatabaseTest($kedua), $kedua);
        $this->assertNotSame(
            (int) DB::selectOne('select connection_id() as id')->id,
            (int) DB::connection(self::KONEKSI_KEDUA)->selectOne('select connection_id() as id')->id,
        );
    }
}
