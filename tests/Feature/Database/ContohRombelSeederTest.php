<?php

namespace Tests\Feature\Database;

use App\Domains\Academic\Enums\Tingkat;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Academic\Services\RombelService;
use App\Domains\Foundation\Enums\Jenjang;
use App\Domains\Foundation\Settings\PengaturanSekolah;
use App\Domains\People\Services\Import\ContohImportSiswa;
use Database\Seeders\ContohRombelSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * ContohRombelSeeder: development rombel for trying the import, created
 * through RombelService until the school year has at least 540 free seats,
 * safe to run twice, refused in production.
 */
class ContohRombelSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    private TahunAjaran $tahunAjaran;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aturJenjang(Jenjang::Sma);
        $this->tahunAjaran = TahunAjaran::factory()->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26', 'status_aktif' => true]);
    }

    protected function tearDown(): void
    {
        putenv(ContohRombelSeeder::VARIABEL);

        parent::tearDown();
    }

    public function test_it_fills_the_active_school_year_to_540_free_seats_through_rombel_service(): void
    {
        $this->spy(RombelService::class)->makePartial();

        $this->seedContoh();

        $this->assertSame(
            ['X-1', 'X-2', 'X-3', 'X-4', 'X-5', 'XI-1', 'XI-2', 'XI-3', 'XI-4', 'XI-5', 'XII-1', 'XII-2', 'XII-3', 'XII-4', 'XII-5'],
            $this->namaRombel($this->tahunAjaran),
        );
        $this->assertSame([36], Rombel::query()->distinct()->pluck('kapasitas')->all());
        app(RombelService::class)->shouldHaveReceived('create')->times(15);
        $this->assertSame(15, Activity::query()->where('subject_type', (new Rombel)->getMorphClass())->where('event', 'created')->count());
    }

    public function test_existing_rombel_are_skipped_and_their_free_seats_count(): void
    {
        Rombel::factory()->for($this->tahunAjaran)->create(['tingkat' => Tingkat::X, 'nomor' => 1, 'kapasitas' => 36]);

        $this->seedContoh();

        $this->assertSame(
            ['X-1', 'X-2', 'X-3', 'X-4', 'X-5', 'X-6', 'XI-1', 'XI-2', 'XI-3', 'XI-4', 'XI-5', 'XII-1', 'XII-2', 'XII-3', 'XII-4'],
            $this->namaRombel($this->tahunAjaran),
        );
    }

    public function test_a_second_run_creates_nothing(): void
    {
        $this->seedContoh();
        $this->seedContoh();

        $this->assertSame(15, Rombel::query()->count());
    }

    public function test_the_school_year_can_be_named_in_an_environment_variable(): void
    {
        $lain = TahunAjaran::factory()->create(['nama' => '2027/2028', 'tanggal_mulai' => '2027-07-12', 'tanggal_selesai' => '2028-06-24', 'status_aktif' => false]);
        putenv(ContohRombelSeeder::VARIABEL.'=2027/2028');

        $this->seedContoh();

        $this->assertSame([], $this->namaRombel($this->tahunAjaran));
        $this->assertCount(15, $this->namaRombel($lain));
    }

    public function test_an_unknown_school_year_is_refused(): void
    {
        putenv(ContohRombelSeeder::VARIABEL.'=2030/2031');

        $this->expectExceptionObject(new RuntimeException('Tahun ajaran 2030/2031 tidak ditemukan.'));

        $this->seedContoh();
    }

    public function test_the_grades_follow_the_school_level(): void
    {
        $this->aturJenjang(Jenjang::Smp);

        $this->seedContoh();

        $this->assertSame(
            ['VII-1', 'VII-2', 'VII-3', 'VII-4', 'VII-5', 'VIII-1', 'VIII-2', 'VIII-3', 'VIII-4', 'VIII-5', 'IX-1', 'IX-2', 'IX-3', 'IX-4', 'IX-5'],
            $this->namaRombel($this->tahunAjaran),
        );
    }

    public function test_it_is_refused_in_production(): void
    {
        $this->app['env'] = 'production';

        $galat = null;

        try {
            $this->seedContoh();
        } catch (RuntimeException $exception) {
            $galat = $exception->getMessage();
        }

        $this->assertSame('Seeder contoh rombel tidak boleh dijalankan di production.', $galat);

        $this->assertSame(0, Rombel::query()->count());
    }

    public function test_the_sample_import_of_500_rows_succeeds_afterwards(): void
    {
        Storage::fake('local');
        Queue::fake();

        $this->seedContoh();

        $this->artisan('simas:contoh-import', ['tahun_ajaran' => '2026/2027', '--baris' => 500])
            ->expectsOutputToContain('500 baris')
            ->assertSuccessful();

        $this->assertCount(1, Storage::disk('local')->files(ContohImportSiswa::FOLDER));
    }

    private function seedContoh(): void
    {
        $this->artisan('db:seed', ['--class' => ContohRombelSeeder::class, '--force' => true])->assertSuccessful();
    }

    /**
     * @return list<string>
     */
    private function namaRombel(TahunAjaran $tahunAjaran): array
    {
        return Rombel::query()->with('tahunAjaran')->where('tahun_ajaran_id', $tahunAjaran->getKey())->orderBy('tingkat')->orderBy('nomor')->get()
            ->map(fn (Rombel $rombel): string => $rombel->nama)->values()->all();
    }

    private function aturJenjang(Jenjang $jenjang): void
    {
        $settings = app(PengaturanSekolah::class);
        $settings->jenjang = $jenjang;
        $settings->save();
    }
}
