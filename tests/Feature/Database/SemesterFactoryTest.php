<?php

namespace Tests\Feature\Database;

use App\Domains\Academic\Enums\JenisSemester;
use App\Domains\Academic\Models\Semester;
use App\Domains\Academic\Models\TahunAjaran;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SemesterFactoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_ganjil_runs_from_the_school_year_start_to_31_december(): void
    {
        $tahunAjaran = TahunAjaran::factory()->create(['nama' => '2030/2031', 'tanggal_mulai' => '2030-07-15', 'tanggal_selesai' => '2031-06-20']);

        $semester = Semester::factory()->for($tahunAjaran)->create();

        $this->assertSame(JenisSemester::Ganjil, $semester->jenis);
        $this->assertSame('2030-07-15', $semester->tanggal_mulai->toDateString());
        $this->assertSame('2030-12-31', $semester->tanggal_selesai->toDateString());
    }

    public function test_genap_runs_from_1_january_to_the_school_year_end(): void
    {
        $tahunAjaran = TahunAjaran::factory()->create(['nama' => '2030/2031', 'tanggal_mulai' => '2030-07-15', 'tanggal_selesai' => '2031-06-20']);

        $semester = Semester::factory()->genap()->for($tahunAjaran)->create();

        $this->assertSame(JenisSemester::Genap, $semester->jenis);
        $this->assertSame('2031-01-01', $semester->tanggal_mulai->toDateString());
        $this->assertSame('2031-06-20', $semester->tanggal_selesai->toDateString());
    }

    public function test_a_semester_with_its_own_school_year_lies_inside_it(): void
    {
        $semester = Semester::factory()->create();

        $this->assertGreaterThanOrEqual($semester->tahunAjaran->tanggal_mulai, $semester->tanggal_mulai);
        $this->assertLessThanOrEqual($semester->tahunAjaran->tanggal_selesai, $semester->tanggal_selesai);
    }
}
