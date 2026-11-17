<?php

namespace Tests\Feature\Domains\Academic\Services;

use App\Domains\Academic\Enums\Tingkat;
use App\Domains\Academic\Models\PenempatanRombel;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Academic\Services\RombelSiswa;
use App\Domains\Foundation\Models\User;
use App\Domains\People\Enums\StatusSiswa;
use App\Domains\People\Models\Siswa;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The one standard way to read a student's rombel in the active school year
 * and the members of a rombel.
 */
class RombelSiswaTest extends TestCase
{
    use LazilyRefreshDatabase;

    private TahunAjaran $aktif;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        $this->aktif = TahunAjaran::factory()->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26', 'status_aktif' => true]);
    }

    public function test_reads_the_rombel_of_the_active_school_year_only(): void
    {
        $lalu = TahunAjaran::factory()->create(['nama' => '2025/2026', 'tanggal_mulai' => '2025-07-14', 'tanggal_selesai' => '2026-06-27']);
        $budi = $this->siswa('Budi');
        $ani = $this->siswa('Ani');
        $xi2 = Rombel::factory()->for($this->aktif)->create(['tingkat' => Tingkat::XI, 'nomor' => 2]);
        PenempatanRombel::factory()->for($budi)->for(Rombel::factory()->for($lalu)->create(['tingkat' => Tingkat::X, 'nomor' => 1]))->create();
        PenempatanRombel::factory()->for($budi)->for($xi2)->create();
        PenempatanRombel::factory()->for($ani)->for(Rombel::factory()->for($lalu)->create(['tingkat' => Tingkat::X, 'nomor' => 2]))->create();

        $this->assertTrue($this->reader()->rombelTahunAjaranAktif($budi)?->is($xi2));
        $this->assertNull($this->reader()->rombelTahunAjaranAktif($ani));
    }

    public function test_without_an_active_school_year_no_student_has_a_rombel(): void
    {
        $budi = $this->siswa('Budi');
        PenempatanRombel::factory()->for($budi)->for(Rombel::factory()->for($this->aktif)->create())->create();
        $this->aktif->forceFill(['status_aktif' => false])->save();

        $this->assertNull($this->reader()->rombelTahunAjaranAktif($budi));
    }

    public function test_members_include_students_who_left_ordered_by_name_and_count_only_active_ones(): void
    {
        $rombel = Rombel::factory()->for($this->aktif)->create(['kapasitas' => 32]);
        $lain = Rombel::factory()->for($this->aktif)->create();
        foreach (['Citra' => StatusSiswa::Aktif, 'Ani' => StatusSiswa::Lulus, 'Budi' => StatusSiswa::Aktif] as $nama => $status) {
            PenempatanRombel::factory()->for($this->siswa($nama, $status))->for($rombel)->create();
        }
        PenempatanRombel::factory()->for($this->siswa('Dodi'))->for($lain)->create();

        $this->assertSame(['Ani', 'Budi', 'Citra'], $this->reader()->anggota($rombel)->map(fn (Siswa $siswa): string => $siswa->user->name)->all());
        $this->assertSame(2, $this->reader()->jumlahSiswaAktif($rombel));
    }

    public function test_the_list_query_loads_the_active_rombel_without_extra_queries_per_student(): void
    {
        $rombel = Rombel::factory()->for($this->aktif)->create(['tingkat' => Tingkat::X, 'nomor' => 3]);
        foreach (['Ani', 'Budi', 'Citra'] as $nama) {
            PenempatanRombel::factory()->for($this->siswa($nama))->for($rombel)->create();
        }

        $siswa = $this->reader()->denganRombelTahunAjaranAktif(Siswa::query())->get();

        $queries = 0;
        \DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->assertSame(['X-3', 'X-3', 'X-3'], $siswa->map(fn (Siswa $satu): ?string => $this->reader()->rombelTermuat($satu)?->nama)->all());
        $this->assertSame(0, $queries);
    }

    private function reader(): RombelSiswa
    {
        return app(RombelSiswa::class);
    }

    private function siswa(string $nama, StatusSiswa $status = StatusSiswa::Aktif): Siswa
    {
        return Siswa::factory()->for(User::factory()->state(['name' => $nama]))->create(['status' => $status]);
    }
}
