<?php

namespace Tests\Feature\Console\Commands;

use App\Domains\Academic\Enums\Tingkat;
use App\Domains\Academic\Models\PenempatanRombel;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Foundation\Models\User;
use App\Domains\People\Enums\StatusImportSiswa;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Services\Import\BacaFileImportSiswa;
use App\Domains\People\Services\Import\BarisImportSiswa;
use App\Domains\People\Services\Import\ContohImportSiswa;
use App\Domains\People\Services\ImportSiswaService;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * simas:contoh-import: an .xlsx built from the import template with fake
 * students that pass the import's check, in the private contoh-import
 * folder; nothing is written to the database; refused in production.
 */
class ContohImportSiswaTest extends TestCase
{
    use LazilyRefreshDatabase;

    private TahunAjaran $tahunAjaran;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
        $this->seed(PeranDanIzinSeeder::class);
        $this->tahunAjaran = TahunAjaran::factory()->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26', 'status_aktif' => true]);
    }

    public function test_the_file_passes_the_import_check_with_new_students_and_shared_parents(): void
    {
        foreach ([1, 2, 3, 4] as $nomor) {
            Rombel::factory()->for($this->tahunAjaran)->create(['tingkat' => Tingkat::X, 'nomor' => $nomor, 'kapasitas' => 40]);
        }

        $this->artisan('simas:contoh-import', ['tahun_ajaran' => '2026/2027', '--baris' => 120])
            ->expectsOutputToContain('120 baris')
            ->assertSuccessful();

        $path = $this->satuFile();
        $baris = $this->baca($path);
        $this->assertCount(120, $baris);

        $import = app(ImportSiswaService::class)->mulai(User::factory()->operator()->create(), $this->tahunAjaran, $this->salinKeFolderImport($path), 'contoh.xlsx');
        $import = app(ImportSiswaService::class)->pratinjau($import);

        $this->assertSame(StatusImportSiswa::Siap, $import->status, (string) json_encode(array_slice($import->galat ?? [], 0, 3)));
        $this->assertSame(120, $import->ringkasan['siswa_baru']);
        $this->assertSame(12, $import->ringkasan['wali_dipakai_bersama'] / 2);
    }

    public function test_every_row_has_its_own_new_nis_a_name_gender_birth_date_rombel_and_two_parents_without_nik(): void
    {
        $rombel = Rombel::factory()->for($this->tahunAjaran)->create(['tingkat' => Tingkat::X, 'nomor' => 1]);
        Siswa::factory()->create(['nis' => '202600001']);
        Siswa::factory()->create(['nis' => '202600003']);
        User::factory()->operator()->create(['username' => '202600002']);

        $this->artisan('simas:contoh-import', ['tahun_ajaran' => '2026/2027', '--baris' => 30])->assertSuccessful();

        $baris = $this->baca($this->satuFile());
        $nis = array_map(fn (BarisImportSiswa $item): string => (string) $item->nilai['nis'], $baris);
        $this->assertCount(30, array_unique($nis));
        $this->assertNotContains('202600001', $nis);
        $this->assertNotContains('202600003', $nis);
        $this->assertNotContains('202600002', $nis);
        $this->assertSame('202600004', $nis[0]);

        foreach ($baris as $item) {
            $this->assertSame([], $item->galat, "baris {$item->nomor}");
            $nilai = $item->nilai;
            $this->assertNotNull($nilai['nama']);
            $this->assertContains($nilai['jenis_kelamin'], ['laki-laki', 'perempuan']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $nilai['tanggal_lahir']);
            $this->assertSame($rombel->id, $nilai['rombel']);
            $this->assertNotNull($nilai['ayah_nama']);
            $this->assertMatchesRegularExpression('/^08\d{8,11}$/', (string) $nilai['ayah_no_hp']);
            $this->assertNotNull($nilai['ibu_nama']);
            $this->assertMatchesRegularExpression('/^08\d{8,11}$/', (string) $nilai['ibu_no_hp']);
            $this->assertNull($nilai['nik']);
            $this->assertNull($nilai['ayah_nik']);
            $this->assertNull($nilai['ibu_nik']);
            $this->assertNull($nilai['wali_nik']);
        }
    }

    public function test_about_one_in_ten_rows_is_a_sibling_sharing_both_parents(): void
    {
        Rombel::factory()->for($this->tahunAjaran)->create(['tingkat' => Tingkat::X, 'nomor' => 1]);

        $this->artisan('simas:contoh-import', ['tahun_ajaran' => '2026/2027', '--baris' => 500])->assertSuccessful();

        $orangTua = [];

        foreach ($this->baca($this->satuFile()) as $item) {
            $kunci = implode('|', [$item->nilai['ayah_nama'], $item->nilai['ayah_no_hp'], $item->nilai['ibu_nama'], $item->nilai['ibu_no_hp']]);
            $orangTua[$kunci] = ($orangTua[$kunci] ?? 0) + 1;
        }

        // 50 pairs of siblings (10% of the rows join a family), the rest alone.
        $this->assertSame(450, count($orangTua));
        $this->assertSame(['1' => 400, '2' => 50], array_count_values(array_map('strval', $orangTua)));
    }

    public function test_rows_are_spread_evenly_over_the_rombel_and_never_beyond_their_free_seats(): void
    {
        $x1 = Rombel::factory()->for($this->tahunAjaran)->create(['tingkat' => Tingkat::X, 'nomor' => 1, 'kapasitas' => 5]);
        $x2 = Rombel::factory()->for($this->tahunAjaran)->create(['tingkat' => Tingkat::X, 'nomor' => 2, 'kapasitas' => 5]);
        $x3 = Rombel::factory()->for($this->tahunAjaran)->create(['tingkat' => Tingkat::X, 'nomor' => 3, 'kapasitas' => 5]);
        foreach ([1, 2, 3, 4] as $urutan) {
            PenempatanRombel::factory()->for(Siswa::factory())->for($x1)->create();
        }

        $this->artisan('simas:contoh-import', ['tahun_ajaran' => '2026/2027', '--baris' => 9])->assertSuccessful();

        $perRombel = array_count_values(array_map(fn (BarisImportSiswa $item): int => (int) $item->nilai['rombel'], $this->baca($this->satuFile())));
        ksort($perRombel);
        $this->assertSame([$x1->id => 1, $x2->id => 4, $x3->id => 4], $perRombel);

        $this->artisan('simas:contoh-import', ['tahun_ajaran' => '2026/2027', '--baris' => 12])
            ->expectsOutputToContain('Kursi kosong di rombel 2026/2027 hanya 11, kurang untuk 12 baris.')
            ->assertFailed();
    }

    public function test_it_writes_nothing_to_the_database(): void
    {
        Rombel::factory()->for($this->tahunAjaran)->create(['tingkat' => Tingkat::X, 'nomor' => 1]);
        $tulis = [];
        DB::listen(function (QueryExecuted $query) use (&$tulis): void {
            if (preg_match('/^\s*(insert|update|delete|replace|truncate|alter|create|drop)\b/i', $query->sql) === 1) {
                $tulis[] = $query->sql;
            }
        });

        $this->artisan('simas:contoh-import', ['tahun_ajaran' => '2026/2027', '--baris' => 20])->assertSuccessful();

        $this->assertSame([], $tulis);
        $this->assertCount(1, Storage::disk('local')->files(ContohImportSiswa::FOLDER));
    }

    public function test_a_school_year_the_import_does_not_accept_or_a_wrong_number_of_rows_is_refused(): void
    {
        Rombel::factory()->for($this->tahunAjaran)->create(['tingkat' => Tingkat::X, 'nomor' => 1]);
        TahunAjaran::factory()->create(['nama' => '2025/2026', 'tanggal_mulai' => '2025-07-14', 'tanggal_selesai' => '2026-06-27']);

        $this->artisan('simas:contoh-import', ['tahun_ajaran' => '2030/2031'])
            ->expectsOutputToContain('Tahun ajaran 2030/2031 tidak ditemukan.')
            ->assertFailed();
        $this->artisan('simas:contoh-import', ['tahun_ajaran' => '2025/2026'])
            ->expectsOutputToContain('Tahun ajaran 2025/2026 sudah lewat; import hanya menerima tahun ajaran aktif atau sesudahnya.')
            ->assertFailed();

        foreach (['0', '1001', 'banyak'] as $jumlah) {
            $this->artisan('simas:contoh-import', ['tahun_ajaran' => '2026/2027', '--baris' => $jumlah])
                ->expectsOutputToContain('Jumlah baris harus 1 sampai 1000.')
                ->assertFailed();
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_it_is_refused_in_production(): void
    {
        Rombel::factory()->for($this->tahunAjaran)->create(['tingkat' => Tingkat::X, 'nomor' => 1]);
        $this->app['env'] = 'production';

        $this->artisan('simas:contoh-import', ['tahun_ajaran' => '2026/2027'])
            ->expectsOutputToContain('Perintah ini tidak boleh dijalankan di production.')
            ->assertFailed();

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function satuFile(): string
    {
        $file = Storage::disk('local')->files(ContohImportSiswa::FOLDER);
        $this->assertCount(1, $file);
        $this->assertStringEndsWith('.xlsx', $file[0]);

        return $file[0];
    }

    /**
     * @return list<BarisImportSiswa>
     */
    private function baca(string $path): array
    {
        $hasil = app(BacaFileImportSiswa::class)->baca(Storage::disk('local')->path($path), $this->tahunAjaran);
        $this->assertSame([], $hasil->galatFile);

        return $hasil->baris;
    }

    private function salinKeFolderImport(string $path): string
    {
        $tujuan = ImportSiswaService::FOLDER.'/contoh-'.md5($path).'.xlsx';
        Storage::disk('local')->copy($path, $tujuan);

        return $tujuan;
    }
}
