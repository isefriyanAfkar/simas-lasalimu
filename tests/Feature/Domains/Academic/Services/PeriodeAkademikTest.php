<?php

namespace Tests\Feature\Domains\Academic\Services;

use App\Domains\Academic\Enums\JenisSemester;
use App\Domains\Academic\Services\PelanggaranAturanAkademik;
use App\Domains\Academic\Services\PeriodeAkademik;
use App\Domains\Academic\Services\TahunAjaranService;
use App\Domains\Foundation\Models\User;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PeriodeAkademikTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_returns_null_when_nothing_is_active(): void
    {
        app(TahunAjaranService::class)->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26']);

        $this->assertNull(app(PeriodeAkademik::class)->tahunAjaranAktif());
        $this->assertNull(app(PeriodeAkademik::class)->semesterAktif());
    }

    public function test_returns_the_active_school_year_and_semester(): void
    {
        $this->seed(PeranDanIzinSeeder::class);
        $this->travelTo('2027-03-10');
        app(TahunAjaranService::class)->create(['nama' => '2025/2026', 'tanggal_mulai' => '2025-07-14', 'tanggal_selesai' => '2026-06-27']);
        $tahunAjaran = app(TahunAjaranService::class)->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26']);
        app(TahunAjaranService::class)->activate(User::factory()->superAdmin()->create(), $tahunAjaran);

        $this->assertSame('2026/2027', app(PeriodeAkademik::class)->tahunAjaranAktif()?->nama);
        $semester = app(PeriodeAkademik::class)->semesterAktif();
        $this->assertSame(JenisSemester::Genap, $semester?->jenis);
        $this->assertSame('2026/2027', $semester?->tahunAjaran->nama);
    }

    public function test_or_fail_variants_throw_a_clear_error_when_nothing_is_active(): void
    {
        try {
            app(PeriodeAkademik::class)->tahunAjaranAktifAtauGagal();
            $this->fail('Expected an exception.');
        } catch (PelanggaranAturanAkademik $exception) {
            $this->assertSame('Belum ada tahun ajaran aktif. Aktifkan tahun ajaran di menu Tahun Ajaran.', $exception->getMessage());
        }

        $this->expectException(PelanggaranAturanAkademik::class);
        $this->expectExceptionMessage('Belum ada semester aktif. Aktifkan tahun ajaran di menu Tahun Ajaran.');

        app(PeriodeAkademik::class)->semesterAktifAtauGagal();
    }
}
