<?php

namespace Tests\Feature\Database;

use App\Domains\Academic\Models\TahunAjaran;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * TahunAjaranFactory names its school years in order from 2100 up, so they
 * never clash with each other or with the years tests name themselves
 * (always below 2100).
 */
class TahunAjaranFactoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_twenty_school_years_from_the_factory_are_unique_and_from_2100_up(): void
    {
        TahunAjaran::factory()->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26']);

        $tahunAjaran = TahunAjaran::factory()->count(20)->create();

        $tahunPertama = $tahunAjaran->map(fn (TahunAjaran $satu): int => (int) substr($satu->nama, 0, 4))->all();
        $this->assertCount(20, array_unique($tahunPertama));
        $this->assertGreaterThanOrEqual(2100, min($tahunPertama));

        foreach ($tahunAjaran as $satu) {
            $tahun = (int) substr($satu->nama, 0, 4);
            $this->assertSame($tahun.'/'.($tahun + 1), $satu->nama);
            $this->assertSame("{$tahun}-07-01", $satu->tanggal_mulai->toDateString());
            $this->assertSame(($tahun + 1).'-06-30', $satu->tanggal_selesai->toDateString());
        }
    }
}
