<?php

namespace Tests\Feature\Domains\People\Services\Import;

use App\Domains\Academic\Enums\Tingkat;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Foundation\Models\User;
use App\Domains\People\Enums\StatusImportSiswa;
use App\Domains\People\Models\ImportSiswa;
use App\Domains\People\Models\Ptk;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Services\Import\BacaFileImportSiswa;
use App\Domains\People\Services\Import\KolomImportSiswa;
use App\Domains\People\Services\Import\PeriksaImportSiswa;
use App\Domains\People\Services\Import\SimpanImportSiswa;
use App\Domains\People\Services\Import\TemplateImportSiswa;
use App\Domains\People\Services\ImportSiswaService;
use App\Domains\People\Services\PelanggaranAturanKesiswaan;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The import around its engine: the queued jobs (once, with a timeout, and
 * gagal when they stop), one import per school year, the size limit, the
 * scheduled worker and cleanup, and the error file.
 *
 * RefreshDatabase (not lazy): one test runs a real queue worker against
 * the jobs table.
 */
class AntreanImportSiswaTest extends TestCase
{
    use MembuatFileImport, RefreshDatabase;

    private const string NIK_GURU = '7404012345670009';

    private User $operator;

    private TahunAjaran $tahunAjaran;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(PeranDanIzinSeeder::class);
        $this->operator = User::factory()->operator()->create();
        $this->tahunAjaran = TahunAjaran::factory()->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26', 'status_aktif' => true]);
        Rombel::factory()->for($this->tahunAjaran)->create(['tingkat' => Tingkat::X, 'nomor' => 1]);
    }

    public function test_an_upload_queues_its_check_which_the_job_runs(): void
    {
        Queue::fake();

        $import = $this->mulai();

        Queue::assertPushed(PeriksaImportSiswa::class, fn (PeriksaImportSiswa $job): bool => $job->importId === $import->id);
        $this->assertSame(StatusImportSiswa::Menunggu, $import->status);

        app()->call([new PeriksaImportSiswa($import->id), 'handle']);

        $this->assertSame(StatusImportSiswa::Siap, $import->fresh()?->status);
    }

    public function test_one_import_per_school_year_at_a_time(): void
    {
        Queue::fake();
        $pertama = $this->mulai(nama: 'pertama.xlsx');
        $path = $this->fileImport($this->tahunAjaran, [$this->baris()]);

        try {
            $this->service()->mulai($this->operator, $this->tahunAjaran, $path, 'kedua.xlsx');
            $this->fail('Import kedua diterima.');
        } catch (PelanggaranAturanKesiswaan $exception) {
            $this->assertSame('Masih ada import untuk tahun ajaran 2026/2027 yang belum selesai (pertama.xlsx, Menunggu diperiksa). Buka import itu lalu simpan atau batalkan, sebelum mengunggah file baru.', $exception->getMessage());
        }

        $this->assertFalse(Storage::disk('local')->exists($path));

        $lain = TahunAjaran::factory()->create(['nama' => '2027/2028', 'tanggal_mulai' => '2027-07-12', 'tanggal_selesai' => '2028-06-24']);
        $this->service()->mulai($this->operator, $lain, $this->fileImport($lain, [$this->baris()]), 'lain.xlsx');

        $this->service()->batal($pertama, $this->operator);
        $kedua = $this->mulai(nama: 'kedua.xlsx');

        $this->assertSame(StatusImportSiswa::Menunggu, $kedua->status);
    }

    public function test_a_file_above_the_size_limit_is_refused_and_deleted(): void
    {
        $path = ImportSiswaService::FOLDER.'/besar.xlsx';
        Storage::disk('local')->put($path, str_repeat('x', ImportSiswaService::MAKS_UKURAN_KB * 1024 + 1));

        try {
            $this->service()->mulai($this->operator, $this->tahunAjaran, $path, 'besar.xlsx');
            $this->fail('File terlalu besar diterima.');
        } catch (ValidationException $exception) {
            $this->assertSame(['file' => ['File terlalu besar: paling besar 2 MB. Bagi menjadi beberapa file, paling banyak 1000 baris per file.']], $exception->errors());
        }

        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertSame(0, ImportSiswa::query()->count());
    }

    public function test_saving_is_queued_once_and_the_job_saves(): void
    {
        Queue::fake();
        $import = $this->service()->pratinjau($this->mulai());

        $import = $this->service()->antrekanSimpan($import, $this->operator);

        $this->assertSame(StatusImportSiswa::Menyimpan, $import->status);
        Queue::assertPushed(SimpanImportSiswa::class, 1);

        try {
            $this->service()->antrekanSimpan($import, $this->operator);
            $this->fail('Disimpan dua kali.');
        } catch (PelanggaranAturanKesiswaan $exception) {
            $this->assertSame('Import ini belum siap disimpan. Periksa file sampai tidak ada galat.', $exception->getMessage());
        }

        app()->call([new SimpanImportSiswa($import->id, $this->operator->id), 'handle']);

        $this->assertSame(StatusImportSiswa::Selesai, $import->fresh()?->status);

        $bergalat = ImportSiswa::factory()->for($this->tahunAjaran)->for($this->operator)->create(['status' => StatusImportSiswa::Bergalat]);

        try {
            $this->service()->antrekanSimpan($bergalat, $this->operator);
            $this->fail('Import bergalat diantrekan.');
        } catch (PelanggaranAturanKesiswaan) {
            $this->assertSame(StatusImportSiswa::Bergalat, $bergalat->fresh()?->status);
        }

        Queue::assertPushed(SimpanImportSiswa::class, 1);
    }

    public function test_the_jobs_run_once_with_a_timeout_below_the_queue_retry_after(): void
    {
        foreach ([new PeriksaImportSiswa(1), new SimpanImportSiswa(1, $this->operator->id)] as $job) {
            $this->assertSame(1, $job->tries);
            $this->assertSame(ImportSiswaService::BATAS_WAKTU_JOB, $job->timeout);
            $this->assertTrue($job->failOnTimeout);
        }

        $this->assertGreaterThan(ImportSiswaService::BATAS_WAKTU_JOB, config('queue.connections.database.retry_after'));
        $this->assertGreaterThan(config('queue.connections.database.retry_after'), ImportSiswaService::BATAS_MACET_DETIK);
    }

    public function test_a_failed_job_ends_the_import_as_failed(): void
    {
        Queue::fake();
        $diperiksa = $this->mulai();
        $diperiksa->update(['status' => StatusImportSiswa::Memeriksa, 'diproses_sejak' => now()]);

        (new PeriksaImportSiswa($diperiksa->id))->failed(new RuntimeException('mati'));

        $diperiksa->refresh();
        $this->assertSame(StatusImportSiswa::Gagal, $diperiksa->status);
        $this->assertSame(ImportSiswaService::PESAN_PERIKSA_GAGAL, $diperiksa->pesan);
        $this->assertNull($diperiksa->path);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $disimpan = $this->mulai();
        $disimpan->update(['status' => StatusImportSiswa::Menyimpan]);
        (new SimpanImportSiswa($disimpan->id, $this->operator->id))->failed(null);
        $this->assertSame(ImportSiswaService::PESAN_SIMPAN_GAGAL, $disimpan->fresh()?->pesan);

        $selesai = ImportSiswa::factory()->for($this->tahunAjaran)->for($this->operator)->create(['status' => StatusImportSiswa::Selesai, 'path' => null]);
        (new SimpanImportSiswa($selesai->id, $this->operator->id))->failed(null);
        $this->assertSame(StatusImportSiswa::Selesai, $selesai->fresh()?->status);
    }

    /**
     * A worker killed mid-job leaves the job reserved. Once retry_after has
     * passed, the next worker finds it with its one attempt used up, fails it
     * without running it again, and failed() ends the import as gagal.
     */
    public function test_a_job_whose_worker_was_killed_ends_the_import_as_failed(): void
    {
        config(['queue.default' => 'database']);
        $import = $this->mulai();
        $import->update(['status' => StatusImportSiswa::Memeriksa, 'diproses_sejak' => now()]);
        DB::table('jobs')->update(['attempts' => 1, 'reserved_at' => now()->subSeconds(config('queue.connections.database.retry_after') + 60)->getTimestamp()]);

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);

        $this->assertSame(StatusImportSiswa::Gagal, $import->fresh()?->status);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    public function test_the_cleanup_ends_stuck_and_abandoned_imports_and_deletes_leftover_files(): void
    {
        Queue::fake();
        $lama = now()->subHours(25);
        $macet = $this->importDengan(StatusImportSiswa::Memeriksa, ['diproses_sejak' => now()->subSeconds(ImportSiswaService::BATAS_MACET_DETIK + 60)]);
        $berjalan = $this->importDengan(StatusImportSiswa::Menyimpan, ['diproses_sejak' => now()->subMinutes(10)]);
        $tidakDiambil = $this->importDengan(StatusImportSiswa::Menunggu, ['updated_at' => $lama]);
        $terlantar = $this->importDengan(StatusImportSiswa::Siap, ['updated_at' => $lama]);
        $baru = $this->importDengan(StatusImportSiswa::Bergalat, []);
        Storage::disk('local')->put('imports/sisa-lama.xlsx', 'x');
        touch(Storage::disk('local')->path('imports/sisa-lama.xlsx'), $lama->getTimestamp());
        Storage::disk('local')->put('imports/sisa-baru.xlsx', 'x');

        $this->artisan('simas:bersihkan-import')
            ->expectsOutputToContain('Import macet ditandai gagal: 2. Import terlantar dibatalkan: 1. File sisa dihapus: 1.')
            ->assertSuccessful();

        $this->assertSame([StatusImportSiswa::Gagal, ImportSiswaService::PESAN_MACET], [$macet->fresh()?->status, $macet->fresh()?->pesan]);
        $this->assertSame(StatusImportSiswa::Menyimpan, $berjalan->fresh()?->status);
        $this->assertSame([StatusImportSiswa::Gagal, ImportSiswaService::PESAN_ANTREAN_MATI], [$tidakDiambil->fresh()?->status, $tidakDiambil->fresh()?->pesan]);
        $this->assertSame([StatusImportSiswa::Dibatalkan, ImportSiswaService::PESAN_TERLANTAR], [$terlantar->fresh()?->status, $terlantar->fresh()?->pesan]);
        $this->assertSame(StatusImportSiswa::Bergalat, $baru->fresh()?->status);

        $sisa = Storage::disk('local')->allFiles();
        sort($sisa);
        $harapan = ['imports/sisa-baru.xlsx', (string) $berjalan->path, (string) $baru->path];
        sort($harapan);
        $this->assertSame($harapan, $sisa);
    }

    public function test_the_scheduler_runs_the_worker_every_minute_without_overlap_and_the_cleanup(): void
    {
        $events = collect(app(Schedule::class)->events());

        $worker = $events->first(fn (Event $event): bool => str_contains((string) $event->command, 'queue:work'));
        $this->assertNotNull($worker);
        $this->assertStringContainsString('--stop-when-empty', (string) $worker->command);
        $this->assertStringContainsString('--timeout='.ImportSiswaService::BATAS_WAKTU_JOB, (string) $worker->command);
        $this->assertSame('* * * * *', $worker->expression);
        $this->assertTrue($worker->withoutOverlapping);
        $this->assertGreaterThan(ImportSiswaService::BATAS_WAKTU_JOB / 60, $worker->expiresAt);

        $bersihkan = $events->first(fn (Event $event): bool => str_contains((string) $event->command, 'simas:bersihkan-import'));
        $this->assertNotNull($bersihkan);
        $this->assertSame('*/5 * * * *', $bersihkan->expression);
    }

    public function test_the_error_file_marks_each_row_and_can_be_read_again(): void
    {
        Queue::fake();
        $guru = Ptk::factory()->for(User::factory()->state(['name' => 'Hafida']))->create();
        $guru->user->isiNik(self::NIK_GURU);
        $guru->user->save();

        $import = $this->service()->pratinjau($this->mulai([
            $this->baris(['nis' => '20261001', 'nik' => self::NIK_GURU]),
            $this->baris(['nis' => '20261002', 'nama' => 'Ani']),
            $this->baris(['nis' => '20261003', 'nama' => 'Citra', 'jenis_kelamin' => null]),
        ]));
        $this->assertSame(StatusImportSiswa::Bergalat, $import->status);

        $path = $this->service()->fileGalat($import, $this->operator);
        $this->assertNotNull($path);
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName(KolomImportSiswa::LEMBAR_DATA);
        $this->assertNotNull($sheet);
        $kolom = count(KolomImportSiswa::KOLOM) + 1;

        $this->assertSame(KolomImportSiswa::KOLOM_GALAT, $sheet->getCell([$kolom, 1])->getValue());
        $this->assertSame('NIK siswa: NIK sudah terdaftar di sistem untuk orang lain.', $sheet->getCell([$kolom, 2])->getValue());
        $this->assertNull($sheet->getCell([$kolom, 3])->getValue());
        $this->assertStringStartsWith('Jenis kelamin: ', (string) $sheet->getCell([$kolom, 4])->getValue());
        $this->assertSame('F8D7DA', $sheet->getStyle(KolomImportSiswa::huruf('nik').'2')->getFill()->getStartColor()->getRGB());
        $this->assertStringNotContainsString('Hafida', (string) json_encode($sheet->toArray()));
        $spreadsheet->disconnectWorksheets();

        $baca = app(BacaFileImportSiswa::class)->baca($path, $this->tahunAjaran);
        $this->assertSame([], $baca->galatFile);
        $this->assertCount(3, $baca->baris);
    }

    public function test_the_save_is_made_in_the_name_of_whoever_pressed_simpan(): void
    {
        Queue::fake();
        $import = $this->service()->pratinjau($this->mulai());
        $lain = User::factory()->operator()->create();
        $logSebelum = (int) Activity::query()->max('id');

        $this->service()->antrekanSimpan($import, $lain);
        Queue::assertPushed(SimpanImportSiswa::class, fn (SimpanImportSiswa $job): bool => $job->pelakuId === $lain->id);
        app()->call([new SimpanImportSiswa($import->id, $lain->id), 'handle']);

        $this->assertSame(StatusImportSiswa::Selesai, $import->fresh()?->status);
        $log = Activity::query()->where('id', '>', $logSebelum)->get();
        $this->assertTrue($log->firstWhere('event', 'import_siswa')?->causer?->is($lain));
        $this->assertSame([$lain->id], $log->pluck('causer_id')->unique()->values()->all());
    }

    public function test_cancelling_is_logged_with_whoever_pressed_it(): void
    {
        Queue::fake();
        $import = $this->mulai();
        $lain = User::factory()->operator()->create();

        $this->service()->batal($import, $lain);

        $log = Activity::query()->where('event', 'import_siswa_dibatalkan')->sole();
        $this->assertSame('kesiswaan', $log->log_name);
        $this->assertTrue($log->causer?->is($lain));
        $this->assertTrue($log->subject?->is($import));
        $this->assertSame(['nama_file' => 'siswa.xlsx', 'tahun_ajaran' => '2026/2027'], $log->properties->all());
    }

    public function test_each_error_file_download_is_logged_without_its_content(): void
    {
        Queue::fake();
        $guru = Ptk::factory()->for(User::factory()->state(['name' => 'Hafida']))->create();
        $guru->user->isiNik(self::NIK_GURU);
        $guru->user->save();
        $lain = User::factory()->operator()->create();
        $bersih = $this->service()->pratinjau($this->mulai());
        $this->assertNull($this->service()->fileGalat($bersih, $lain));
        $this->service()->batal($bersih, $lain);
        $import = $this->service()->pratinjau($this->mulai([$this->baris(['nik' => self::NIK_GURU])]));

        foreach ([$this->operator, $lain] as $pelaku) {
            $path = $this->service()->fileGalat($import, $pelaku);
            $this->assertNotNull($path);
            unlink($path);
        }

        $log = Activity::query()->where('event', 'file_galat_import_diunduh')->orderBy('id')->get();
        $this->assertSame([$this->operator->id, $lain->id], $log->pluck('causer_id')->all());
        $this->assertTrue($log[0]->subject?->is($import));
        $this->assertSame(['nama_file' => 'siswa.xlsx', 'tahun_ajaran' => '2026/2027'], $log[0]->properties->all());
        $this->assertStringNotContainsString(self::NIK_GURU, (string) $log->toJson());
    }

    public function test_a_file_used_by_another_import_is_refused_and_kept(): void
    {
        Queue::fake();
        $pertama = $this->mulai();
        $lain = TahunAjaran::factory()->create(['nama' => '2027/2028', 'tanggal_mulai' => '2027-07-12', 'tanggal_selesai' => '2028-06-24']);

        foreach ([$lain, $this->tahunAjaran] as $tahunAjaran) {
            try {
                $this->service()->mulai($this->operator, $tahunAjaran, (string) $pertama->path, 'curian.xlsx');
                $this->fail("Path import lain diterima untuk {$tahunAjaran->nama}.");
            } catch (ValidationException $exception) {
                $this->assertSame(['file' => ['File import tidak ditemukan. Unggah ulang file .xlsx.']], $exception->errors());
            }

            $this->assertTrue(Storage::disk('local')->exists((string) $pertama->path));
        }

        $this->assertSame(1, ImportSiswa::query()->count());
    }

    public function test_the_error_file_and_the_template_leave_no_other_temporary_file(): void
    {
        Queue::fake();
        $import = $this->service()->pratinjau($this->mulai([$this->baris(['jenis_kelamin' => null])]));
        $sebelum = $this->fileSementara();

        $galat = $this->service()->fileGalat($import, $this->operator);
        $template = app(TemplateImportSiswa::class)->fileSementara($this->tahunAjaran);

        $this->assertNotNull($galat);
        $this->assertSame([], app(BacaFileImportSiswa::class)->baca($template, $this->tahunAjaran)->galatFile);
        $this->assertEqualsCanonicalizing([...$sebelum, realpath($galat), realpath($template)], $this->fileSementara());
        unlink($galat);
        unlink($template);
        $this->assertEqualsCanonicalizing($sebelum, $this->fileSementara());
    }

    public function test_a_job_for_an_import_that_is_already_over_does_nothing(): void
    {
        Queue::fake();
        $import = $this->service()->pratinjau($this->mulai());
        $this->service()->batal($import, $this->operator);

        app()->call([new PeriksaImportSiswa($import->id), 'handle']);
        app()->call([new SimpanImportSiswa($import->id, $this->operator->id), 'handle']);

        $this->assertSame(StatusImportSiswa::Dibatalkan, $import->fresh()?->status);
    }

    public function test_the_cleanup_never_ends_an_import_that_moved_on_meanwhile(): void
    {
        Queue::fake();
        $macet = $this->importDengan(StatusImportSiswa::Memeriksa, ['diproses_sejak' => now()->subSeconds(ImportSiswaService::BATAS_MACET_DETIK + 60)]);
        $terlantar = $this->importDengan(StatusImportSiswa::Siap, ['updated_at' => now()->subHours(25)]);
        $berpindah = [];

        // Right after the cleanup found them: the stuck check ends, and an
        // operator presses Simpan on the abandoned one.
        ImportSiswa::retrieved(function (ImportSiswa $dibaca) use ($macet, $terlantar, &$berpindah): void {
            foreach ([[$macet, StatusImportSiswa::Siap], [$terlantar, StatusImportSiswa::Menyimpan]] as [$import, $status]) {
                if (! isset($berpindah[$import->id]) && $dibaca->is($import)) {
                    $berpindah[$import->id] = true;
                    ImportSiswa::query()->whereKey($import->id)->update(['status' => $status->value, 'diproses_sejak' => null]);
                }
            }
        });

        $hasil = $this->service()->bersihkan();

        $this->assertCount(2, $berpindah);
        $this->assertSame([0, 0], [$hasil['macet'], $hasil['terlantar']]);
        $this->assertSame(StatusImportSiswa::Siap, $macet->fresh()?->status);
        $this->assertSame(StatusImportSiswa::Menyimpan, $terlantar->fresh()?->status);
        $this->assertTrue(Storage::disk('local')->exists((string) $macet->path));
        $this->assertTrue(Storage::disk('local')->exists((string) $terlantar->path));
    }

    /**
     * One worker, one connection: a check job that dies with an exception
     * ran with innodb_snapshot_isolation OFF, and the next job on the same
     * connection finds the value the session had before.
     */
    public function test_the_next_job_on_the_worker_gets_the_session_value_back_after_an_import_job_failed(): void
    {
        config(['queue.default' => 'database']);
        DB::statement('SET SESSION innodb_snapshot_isolation = ON');
        $import = $this->mulai([$this->baris(['nis' => '20261009', 'nama' => 'Gagal'])]);
        $selamaImport = null;

        Siswa::creating(function () use (&$selamaImport): never {
            $selamaImport = DB::selectOne("SHOW SESSION VARIABLES LIKE 'innodb_snapshot_isolation'")?->Value;

            throw new RuntimeException('job mati di tengah jalan');
        });
        CatatIsolasiSnapshot::dispatch();
        CatatIsolasiSnapshot::$nilai = null;

        // A high memory limit: in a full test run this process is large, and the
        // worker would stop after the first job, before the one that checks.
        $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0, '--memory' => 4096]);

        $this->assertSame('OFF', $selamaImport);
        $this->assertSame(StatusImportSiswa::Gagal, $import->fresh()?->status);
        $this->assertSame('ON', CatatIsolasiSnapshot::$nilai);
        $this->assertSame('ON', DB::selectOne("SHOW SESSION VARIABLES LIKE 'innodb_snapshot_isolation'")?->Value);
    }

    /**
     * The files in the system temporary folder that the error file or the
     * template could have made: their prefix, which tempnam() cuts to three
     * letters on Windows ("gal", "tem"). Other programs keep writing there,
     * so the rest is ignored.
     *
     * @return list<string>
     */
    private function fileSementara(): array
    {
        $file = [];

        foreach (['gal', 'tem'] as $awalan) {
            foreach (glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.$awalan.'*') ?: [] as $path) {
                if (is_file($path) && ($asli = realpath($path)) !== false) {
                    $file[] = $asli;
                }
            }
        }

        return $file;
    }

    /**
     * @param  list<array<string, mixed>>|null  $baris
     */
    private function mulai(?array $baris = null, string $nama = 'siswa.xlsx'): ImportSiswa
    {
        return $this->service()->mulai($this->operator, $this->tahunAjaran, $this->fileImport($this->tahunAjaran, $baris ?? [$this->baris()]), $nama);
    }

    /**
     * @param  array<string, mixed>  $isi
     * @return array<string, mixed>
     */
    private function baris(array $isi = []): array
    {
        return [...['nis' => '20261001', 'nama' => 'Budi', 'jenis_kelamin' => 'L', 'rombel' => 'X-1'], ...$isi];
    }

    /**
     * An import of another school year (so they do not block each other)
     * with its file, then the given columns written as they are.
     *
     * @param  array<string, mixed>  $kolom
     */
    private function importDengan(StatusImportSiswa $status, array $kolom): ImportSiswa
    {
        $tahun = 2040 + ImportSiswa::query()->count();
        $tahunAjaran = TahunAjaran::factory()->create(['nama' => $tahun.'/'.($tahun + 1), 'tanggal_mulai' => "{$tahun}-07-01", 'tanggal_selesai' => ($tahun + 1).'-06-30']);
        $import = ImportSiswa::factory()->for($tahunAjaran)->for($this->operator)->create(['status' => $status]);
        Storage::disk('local')->put((string) $import->path, 'x');
        ImportSiswa::query()->whereKey($import->id)->update($kolom);

        return $import;
    }

    private function service(): ImportSiswaService
    {
        return app(ImportSiswaService::class);
    }
}

/**
 * A job that notes the innodb_snapshot_isolation of its session.
 */
final class CatatIsolasiSnapshot implements ShouldQueue
{
    use Queueable;

    public static ?string $nilai = null;

    public function handle(): void
    {
        self::$nilai = DB::selectOne("SHOW SESSION VARIABLES LIKE 'innodb_snapshot_isolation'")?->Value;
    }
}
