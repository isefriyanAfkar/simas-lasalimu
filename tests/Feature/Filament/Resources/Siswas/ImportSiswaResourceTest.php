<?php

namespace Tests\Feature\Filament\Resources\Siswas;

use App\Domains\Academic\Enums\Tingkat;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Foundation\Models\User;
use App\Domains\People\Enums\StatusImportSiswa;
use App\Domains\People\Models\ImportSiswa;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Services\Import\PeriksaImportSiswa;
use App\Domains\People\Services\Import\SimpanImportSiswa;
use App\Domains\People\Services\ImportSiswaService;
use App\Filament\Resources\Siswas\Pages\ImportSiswaPage;
use App\Filament\Resources\Siswas\Pages\ListSiswas;
use Database\Seeders\PeranDanIzinSeeder;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Domains\People\Services\Import\MembuatFileImport;
use Tests\TestCase;

/**
 * The import on the screens: the template download, the upload, going back
 * to an import that is not over, and the import page (status, errors,
 * moves, Simpan, Batal, error file). Every action only calls
 * ImportSiswaService.
 */
class ImportSiswaResourceTest extends TestCase
{
    use LazilyRefreshDatabase, MembuatFileImport;

    private User $operator;

    private TahunAjaran $tahunAjaran;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
        $this->seed(PeranDanIzinSeeder::class);
        Filament::setCurrentPanel('admin');
        $this->tahunAjaran = TahunAjaran::factory()->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26', 'status_aktif' => true]);
        Rombel::factory()->for($this->tahunAjaran)->create(['tingkat' => Tingkat::X, 'nomor' => 1]);
        $this->operator = User::factory()->operator()->create();
        $this->actingAs($this->operator);
    }

    public function test_the_template_is_downloaded_for_the_chosen_school_year(): void
    {
        Livewire::test(ListSiswas::class)
            ->callAction('unduhTemplateImport', ['tahun_ajaran_id' => $this->tahunAjaran->id])
            ->assertHasNoActionErrors()
            ->assertFileDownloaded('template-import-siswa-20262027.xlsx');
    }

    public function test_an_upload_starts_the_import_and_opens_its_page(): void
    {
        $file = UploadedFile::fake()->createWithContent('data siswa.xlsx', $this->isiFileImport($this->tahunAjaran, [['nis' => '20261001', 'nama' => 'Budi', 'jenis_kelamin' => 'L']]));

        $komponen = Livewire::test(ListSiswas::class)
            ->callAction('importSiswa', ['tahun_ajaran_id' => $this->tahunAjaran->id, 'file' => $file])
            ->assertHasNoActionErrors();

        $import = ImportSiswa::query()->sole();
        $komponen->assertRedirect(ImportSiswaPage::getUrl(['import' => $import]));
        $this->assertSame('data siswa.xlsx', $import->nama_file);
        $this->assertMatchesRegularExpression('#^imports/[0-9a-f-]{36}\.xlsx$#', (string) $import->path);
        $this->assertTrue(Storage::disk('local')->exists((string) $import->path));
        $this->assertTrue($import->user->is($this->operator));
        Queue::assertPushed(PeriksaImportSiswa::class);
    }

    public function test_a_file_above_the_limit_gets_a_message_on_the_field(): void
    {
        $file = UploadedFile::fake()->create('besar.xlsx', ImportSiswaService::MAKS_UKURAN_KB + 1, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        Livewire::test(ListSiswas::class)
            ->callAction('importSiswa', ['tahun_ajaran_id' => $this->tahunAjaran->id, 'file' => $file])
            ->assertHasActionErrors(['file'])
            ->tap(fn ($komponen) => $this->assertContains(ImportSiswaService::pesanTerlaluBesar(), $komponen->errors()->get('mountedActions.0.data.file')));

        $this->assertSame(0, ImportSiswa::query()->count());
    }

    public function test_a_second_upload_for_the_same_school_year_is_refused_with_a_message(): void
    {
        ImportSiswa::factory()->for($this->tahunAjaran)->for($this->operator)->create(['nama_file' => 'pertama.xlsx', 'status' => StatusImportSiswa::Siap]);
        $file = UploadedFile::fake()->createWithContent('kedua.xlsx', $this->isiFileImport($this->tahunAjaran, [['nis' => '20261001']]));

        Livewire::test(ListSiswas::class)
            ->callAction('importSiswa', ['tahun_ajaran_id' => $this->tahunAjaran->id, 'file' => $file])
            ->assertNotified('Masih ada import untuk tahun ajaran 2026/2027 yang belum selesai (pertama.xlsx, Siap disimpan). Buka import itu lalu simpan atau batalkan, sebelum mengunggah file baru.');

        $this->assertSame(1, ImportSiswa::query()->count());
    }

    public function test_an_import_that_is_not_over_can_be_opened_from_the_list(): void
    {
        Livewire::test(ListSiswas::class)->assertActionHidden('importBerjalan');

        $import = ImportSiswa::factory()->for($this->tahunAjaran)->for($this->operator)->create(['nama_file' => 'siswa.xlsx', 'status' => StatusImportSiswa::Bergalat]);

        Livewire::test(ListSiswas::class)
            ->assertActionVisible('importBerjalan')
            ->assertActionHasLabel('importBerjalan', 'Import berjalan: siswa.xlsx')
            ->assertActionHasUrl('importBerjalan', ImportSiswaPage::getUrl(['import' => $import]));
    }

    public function test_without_the_import_permission_there_is_no_import(): void
    {
        // An operator (who may open the panel and the Siswa list) without
        // the import permission.
        Role::findByName('operator', 'web')->revokePermissionTo('Import:Siswa');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::factory()->operator()->create();
        $this->actingAs($user);
        $this->get(ListSiswas::getUrl())->assertOk();
        $import = ImportSiswa::factory()->for($this->tahunAjaran)->for($this->operator)->create(['status' => StatusImportSiswa::Bergalat]);

        Livewire::test(ListSiswas::class)
            ->assertActionHidden('importSiswa')
            ->assertActionHidden('unduhTemplateImport')
            ->assertActionHidden('importBerjalan');

        $this->get(ImportSiswaPage::getUrl(['import' => $import]))->assertForbidden();
        $this->assertFalse($user->can('import', Siswa::class));
    }

    public function test_the_page_refreshes_itself_only_while_the_import_waits_or_runs(): void
    {
        $import = ImportSiswa::factory()->for($this->tahunAjaran)->for($this->operator)->create(['status' => StatusImportSiswa::Memeriksa]);

        Livewire::test(ImportSiswaPage::class, ['import' => $import->id])
            ->assertSee('Sedang diperiksa')
            ->assertSeeHtml('wire:poll.3s="segarkan"')
            ->assertActionHidden('simpan')
            ->assertActionHidden('batal');

        $import->update(['status' => StatusImportSiswa::Gagal, 'pesan' => ImportSiswaService::PESAN_PERIKSA_GAGAL]);

        Livewire::test(ImportSiswaPage::class, ['import' => $import->id])
            ->assertSee(ImportSiswaService::PESAN_PERIKSA_GAGAL)
            ->assertDontSeeHtml('wire:poll');
    }

    public function test_a_clean_check_shows_the_counts_and_moves_and_is_saved_after_confirmation(): void
    {
        $import = ImportSiswa::factory()->for($this->tahunAjaran)->for($this->operator)->create([
            'status' => StatusImportSiswa::Siap,
            'ringkasan' => ['siswa_baru' => 12, 'siswa_diperbarui' => 3, 'siswa_dipindah' => 1, 'siswa_tanpa_perubahan' => 0, 'wali_baru' => 20, 'wali_dipakai_bersama' => 2, 'baris_bergalat' => 0],
            'galat' => [],
            'pindah' => [['baris' => 5, 'nis' => '20261005', 'nama' => 'Dodi', 'dari' => 'X-1', 'ke' => 'X-2']],
        ]);

        Livewire::test(ImportSiswaPage::class, ['import' => $import->id])
            ->assertSee(['Siswa baru', '12', 'Wali dipakai bersama', 'Dodi', 'X-2'])
            ->assertActionHidden('unduhGalat')
            ->assertActionExists('simpan', fn (Action $action): bool => str_starts_with((string) $action->getModalDescription(), '12 siswa baru, 3 diperbarui, 1 dipindah rombel, 20 wali baru.'))
            ->callAction('simpan')
            ->assertNotified('Import sedang disimpan.');

        $this->assertSame(StatusImportSiswa::Menyimpan, $import->fresh()?->status);
        Queue::assertPushed(SimpanImportSiswa::class, fn (SimpanImportSiswa $job): bool => $job->importId === $import->id);
    }

    public function test_the_page_actions_check_the_permission_when_they_are_called(): void
    {
        $siap = ImportSiswa::factory()->for($this->tahunAjaran)->for($this->operator)->create(['status' => StatusImportSiswa::Siap, 'ringkasan' => [], 'galat' => [], 'pindah' => []]);
        $path = $this->fileImport($this->tahunAjaran, [['nis' => '20261001', 'nama' => 'Budi']]);
        $lainTahun = TahunAjaran::factory()->create(['nama' => '2027/2028', 'tanggal_mulai' => '2027-07-12', 'tanggal_selesai' => '2028-06-24']);
        $bergalat = ImportSiswa::factory()->for($lainTahun)->for($this->operator)->create([
            'path' => $path,
            'status' => StatusImportSiswa::Bergalat,
            'galat' => [['baris' => 2, 'kolom' => 'jenis_kelamin', 'pesan' => 'Jenis kelamin wajib diisi.']],
        ]);
        $halamanSiap = Livewire::test(ImportSiswaPage::class, ['import' => $siap->id]);
        $halamanBergalat = Livewire::test(ImportSiswaPage::class, ['import' => $bergalat->id]);

        // The permission is taken away while the pages are open; the calls
        // below are what the browser would still send (callAction() would
        // stop at the button being hidden).
        Role::findByName('operator', 'web')->revokePermissionTo('Import:Siswa');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $halamanSiap->call('mountAction', 'simpan')->call('callMountedAction');
        $halamanSiap->call('mountAction', 'batal')->call('callMountedAction');
        $halamanBergalat->call('mountAction', 'unduhGalat')->call('callMountedAction')->assertNoFileDownloaded();

        $this->assertSame(StatusImportSiswa::Siap, $siap->fresh()?->status);
        Queue::assertNothingPushed();
        $this->assertSame(0, Activity::query()->whereIn('event', ['import_siswa_dibatalkan', 'file_galat_import_diunduh'])->count());
    }

    public function test_every_panel_page_warns_while_an_import_is_checked_or_saved(): void
    {
        $peringatan = ['Import siswa sedang diproses.', 'Penyimpanan data siswa dan wali murid, serta perubahan username, email, NIK, atau foto akun, bisa tertunda sampai import selesai. Masuk dan keluar tidak terpengaruh.'];
        $import = ImportSiswa::factory()->for($this->tahunAjaran)->for($this->operator)->create(['status' => StatusImportSiswa::Siap]);
        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin);

        $this->get(Dashboard::getUrl())->assertOk()->assertDontSee($peringatan[0]);

        // A save that is only queued holds nothing yet.
        $import->update(['status' => StatusImportSiswa::Menyimpan, 'diproses_sejak' => null]);
        $this->get(Dashboard::getUrl())->assertDontSee($peringatan[0]);

        foreach ([StatusImportSiswa::Memeriksa, StatusImportSiswa::Menyimpan] as $status) {
            $import->update(['status' => $status, 'diproses_sejak' => now()]);
            $this->get(Dashboard::getUrl())->assertSee($peringatan);
        }

        $this->actingAs($this->operator);
        $this->get(ListSiswas::getUrl())->assertSee($peringatan);
    }

    public function test_a_check_with_errors_lists_them_offers_the_error_file_and_can_be_cancelled(): void
    {
        $path = $this->fileImport($this->tahunAjaran, [['nis' => '20261001', 'nama' => 'Budi']]);
        $import = ImportSiswa::factory()->for($this->tahunAjaran)->for($this->operator)->create([
            'nama_file' => 'siswa.xlsx',
            'path' => $path,
            'status' => StatusImportSiswa::Bergalat,
            'ringkasan' => ['siswa_baru' => 0, 'siswa_diperbarui' => 0, 'siswa_dipindah' => 0, 'siswa_tanpa_perubahan' => 0, 'wali_baru' => 0, 'wali_dipakai_bersama' => 0, 'baris_bergalat' => 1],
            'galat' => [['baris' => 2, 'kolom' => 'jenis_kelamin', 'pesan' => 'Jenis kelamin wajib diisi.']],
        ]);

        Livewire::test(ImportSiswaPage::class, ['import' => $import->id])
            ->assertSee(['Jenis kelamin', 'Jenis kelamin wajib diisi.'])
            ->assertActionHidden('simpan')
            ->callAction('unduhGalat')
            ->assertFileDownloaded('galat-siswa.xlsx')
            ->callAction('batal')
            ->assertNotified('Import dibatalkan.');

        $this->assertSame(StatusImportSiswa::Dibatalkan, $import->fresh()?->status);
        $this->assertFalse(Storage::disk('local')->exists($path));
    }
}
