<?php

namespace Tests\Feature\Database;

use App\Domains\Academic\Models\Semester;
use App\Domains\Academic\Models\TahunAjaran;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The database itself, not only the services, keeps at most one active school
 * year and one active semester (generated column penanda_aktif + unique index).
 */
class SatuPeriodeAktifTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_database_rejects_a_second_active_school_year(): void
    {
        [$pertama, $kedua] = TahunAjaran::factory()->count(2)->create();
        DB::table('tahun_ajaran')->where('id', $pertama->id)->update(['status_aktif' => true]);

        $this->assertDuplicateKey(fn () => DB::table('tahun_ajaran')->where('id', $kedua->id)->update(['status_aktif' => true]));

        $this->assertSame(1, DB::table('tahun_ajaran')->where('status_aktif', true)->count());
    }

    public function test_database_rejects_a_second_active_semester(): void
    {
        $tahunAjaran = TahunAjaran::factory()->create();
        $ganjil = Semester::factory()->for($tahunAjaran)->create(['status_aktif' => true]);
        $genap = Semester::factory()->genap()->for($tahunAjaran)->create();

        $this->assertDuplicateKey(fn () => DB::table('semester')->where('id', $genap->id)->update(['status_aktif' => true]));

        $this->assertTrue($ganjil->fresh()->status_aktif);
        $this->assertFalse($genap->fresh()->status_aktif);
    }

    public function test_many_inactive_rows_are_allowed(): void
    {
        TahunAjaran::factory()->count(3)->create();

        $this->assertSame(3, DB::table('tahun_ajaran')->whereNull('penanda_aktif')->count());
    }

    private function assertDuplicateKey(callable $operation): void
    {
        try {
            $operation();
        } catch (QueryException $exception) {
            $this->assertSame(1062, $exception->errorInfo[1] ?? null, $exception->getMessage());

            return;
        }

        $this->fail('The database accepted a second active row.');
    }
}
