<?php

namespace Tests\Feature\Filament\Resources\ActivityLogs;

use App\Domains\Academic\Services\MataPelajaranService;
use App\Domains\Academic\Services\RombelService;
use App\Domains\Academic\Services\TahunAjaranService;
use App\Domains\Foundation\Enums\Jenjang;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PengaturanSekolahService;
use App\Domains\Foundation\Services\UserService;
use App\Domains\Foundation\Settings\PengaturanSekolah;
use App\Domains\People\Models\Ptk;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Models\StatusKepegawaian;
use App\Domains\People\Services\PtkService;
use App\Domains\People\Services\SiswaService;
use App\Domains\People\Services\StatusKepegawaianService;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use Database\Seeders\PeranDanIzinSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityLogResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        Filament::setCurrentPanel('admin');
    }

    public function test_operator_gets_403_on_the_activity_log(): void
    {
        $this->actingAs(User::factory()->operator()->create())
            ->get(ActivityLogResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_operator_with_every_user_permission_still_gets_403(): void
    {
        $operator = User::factory()->operator()->create();
        $operator->givePermissionTo(PeranDanIzinSeeder::USER_PERMISSIONS);

        $this->actingAs($operator)
            ->get(ActivityLogResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_super_admin_sees_actor_action_object_and_changes(): void
    {
        $superAdmin = User::factory()->superAdmin()->create(['name' => 'Kepala TU']);
        $operator = User::factory()->operator()->create(['username' => 'siti']);
        app(UserService::class)->deactivate($superAdmin, $operator);
        $activity = Activity::query()->sole();

        $this->actingAs($superAdmin);

        Livewire::test(ListActivityLogs::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$activity])
            ->assertTableColumnStateSet('causer.name', 'Kepala TU', $activity)
            ->assertTableColumnFormattedStateSet('event', 'Pengguna dinonaktifkan', $activity)
            ->assertTableColumnStateSet('objek', 'siti', $activity)
            ->assertTableColumnStateSet('rincian', 'status_aktif: aktif → nonaktif', $activity);
    }

    public function test_school_settings_change_shows_its_object_action_and_changes(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        app(PengaturanSekolahService::class)->simpan($superAdmin, ['nama_sekolah' => 'SMA Negeri 1 Lasalimu']);
        $activity = Activity::query()->where('log_name', 'pengaturan')->sole();

        $this->actingAs($superAdmin);

        Livewire::test(ListActivityLogs::class)
            ->assertTableColumnStateSet('objek', 'Pengaturan sekolah', $activity)
            ->assertTableColumnFormattedStateSet('event', 'Pengaturan sekolah diubah', $activity)
            ->assertTableColumnStateSet('rincian', 'nama_sekolah: — → SMA Negeri 1 Lasalimu', $activity);
    }

    public function test_school_year_and_semester_entries_name_their_object(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($superAdmin);
        $this->travelTo('2026-09-15');
        $tahunAjaran = app(TahunAjaranService::class)->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26']);
        app(TahunAjaranService::class)->activate($superAdmin, $tahunAjaran);

        $tahunAjaranLog = Activity::query()->where('event', 'tahun_ajaran_diaktifkan')->sole();
        $semesterLog = Activity::query()->where('event', 'semester_diaktifkan')->sole();
        $createdLog = Activity::query()->where('event', 'created')->where('subject_type', $tahunAjaran->getMorphClass())->sole();

        Livewire::test(ListActivityLogs::class)
            ->assertTableColumnStateSet('objek', 'TA 2026/2027', $tahunAjaranLog)
            ->assertTableColumnFormattedStateSet('event', 'Tahun ajaran diaktifkan', $tahunAjaranLog)
            ->assertTableColumnStateSet('objek', 'Ganjil 2026/2027', $semesterLog)
            ->assertTableColumnStateSet('causer.name', $superAdmin->name, $createdLog)
            ->assertTableColumnFormattedStateSet('event', 'Tahun ajaran dibuat', $createdLog);
    }

    public function test_activation_entry_shows_the_replaced_period_even_after_it_is_deleted(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($superAdmin);
        $service = app(TahunAjaranService::class);
        $lama = $service->create(['nama' => '2025/2026', 'tanggal_mulai' => '2025-07-14', 'tanggal_selesai' => '2026-06-27']);
        $baru = $service->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26']);
        $this->travelTo('2026-03-02');
        $service->activate($superAdmin, $lama);
        $this->travelTo('2026-09-15');
        $service->activate($superAdmin, $baru);
        $service->delete($lama->fresh());

        $tahunAjaranLog = Activity::query()->where('event', 'tahun_ajaran_diaktifkan')->latest('id')->first();
        $semesterLog = Activity::query()->where('event', 'semester_diaktifkan')->latest('id')->first();

        Livewire::test(ListActivityLogs::class)
            ->assertTableColumnStateSet('rincian', 'status_aktif: nonaktif → aktif; menggantikan TA 2025/2026', $tahunAjaranLog)
            ->assertTableColumnStateSet('rincian', 'status_aktif: nonaktif → aktif; menggantikan Genap 2025/2026', $semesterLog);
    }

    public function test_subject_status_entries_name_the_subject(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($superAdmin);
        $mataPelajaran = app(MataPelajaranService::class)->create(['kode' => 'MTK', 'nama' => 'Matematika']);
        app(MataPelajaranService::class)->deactivate($superAdmin, $mataPelajaran);

        $log = Activity::query()->where('event', 'mata_pelajaran_dinonaktifkan')->sole();

        Livewire::test(ListActivityLogs::class)
            ->assertTableColumnStateSet('objek', 'MTK Matematika', $log)
            ->assertTableColumnFormattedStateSet('event', 'Mata pelajaran dinonaktifkan', $log)
            ->assertTableColumnStateSet('rincian', 'status_aktif: aktif → nonaktif', $log);
    }

    public function test_rombel_entries_name_the_rombel_and_its_school_year(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $settings = app(PengaturanSekolah::class);
        $settings->jenjang = Jenjang::Sma;
        $settings->save();
        $tahunAjaran = app(TahunAjaranService::class)->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26']);
        $rombel = app(RombelService::class)->create(['tahun_ajaran_id' => $tahunAjaran->id, 'tingkat' => 10, 'nomor' => 1]);

        $log = Activity::query()->where('event', 'created')->where('subject_type', $rombel->getMorphClass())->sole();

        Livewire::test(ListActivityLogs::class)
            ->assertTableColumnStateSet('objek', 'X-1 2026/2027', $log)
            ->assertTableColumnFormattedStateSet('event', 'Rombel dibuat', $log);
    }

    public function test_entries_of_a_deleted_rombel_keep_its_readable_name(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $settings = app(PengaturanSekolah::class);
        $settings->jenjang = Jenjang::Sma;
        $settings->save();
        $tahunAjaran = app(TahunAjaranService::class)->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26']);
        $rombel = app(RombelService::class)->create(['tahun_ajaran_id' => $tahunAjaran->id, 'tingkat' => 10, 'nomor' => 1]);
        app(RombelService::class)->delete($rombel);

        $created = Activity::query()->where('event', 'created')->where('subject_type', $rombel->getMorphClass())->sole();
        $deleted = Activity::query()->where('event', 'deleted')->where('subject_type', $rombel->getMorphClass())->sole();

        Livewire::test(ListActivityLogs::class)
            ->assertTableColumnStateSet('objek', 'X-1 2026/2027', $created)
            ->assertTableColumnStateSet('objek', 'X-1 2026/2027', $deleted)
            ->assertTableColumnStateSet('rincian', "tahun_ajaran_id: {$tahunAjaran->id}; tingkat: X; nomor: 1; kapasitas: —", $deleted);
    }

    public function test_entries_of_a_deleted_school_year_and_its_semesters_keep_their_readable_names(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($superAdmin);
        $service = app(TahunAjaranService::class);
        $tahunAjaran = $service->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26']);
        $lain = $service->create(['nama' => '2027/2028', 'tanggal_mulai' => '2027-07-12', 'tanggal_selesai' => '2028-06-24']);
        $this->travelTo('2026-09-15');
        $service->activate($superAdmin, $tahunAjaran);
        $service->activate($superAdmin, $lain);
        $ganjil = $tahunAjaran->semesterGanjil;
        $service->delete($tahunAjaran->fresh());

        $tahunAjaranCreated = Activity::query()->where('event', 'created')->where('subject_type', $tahunAjaran->getMorphClass())->where('subject_id', $tahunAjaran->id)->sole();
        $tahunAjaranActivated = Activity::query()->where('event', 'tahun_ajaran_diaktifkan')->where('subject_id', $tahunAjaran->id)->sole();
        $semesterDeleted = Activity::query()->where('event', 'deleted')->where('subject_type', $ganjil->getMorphClass())->where('subject_id', $ganjil->id)->sole();

        Livewire::test(ListActivityLogs::class)
            ->assertTableColumnStateSet('objek', 'TA 2026/2027', $tahunAjaranCreated)
            ->assertTableColumnStateSet('objek', 'TA 2026/2027', $tahunAjaranActivated)
            ->assertTableColumnStateSet('objek', 'Ganjil 2026/2027', $semesterDeleted);
    }

    public function test_nik_and_photo_changes_show_as_words_without_values(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('foto-pengguna/lama.png', UploadedFile::fake()->image('lama.png')->getContent());
        Storage::disk('local')->put('foto-pengguna/baru.png', UploadedFile::fake()->image('baru.png')->getContent());
        $superAdmin = User::factory()->superAdmin()->create();
        $user = User::factory()->operator()->create(['username' => 'siti', 'foto' => 'foto-pengguna/lama.png']);
        $user->isiNik('7404012345670001');
        $user->save();

        app(UserService::class)->update($superAdmin, $user, [
            'name' => $user->name,
            'username' => 'siti',
            'email' => $user->email,
            'peran' => 'operator',
            'nik' => '7404012345670002',
            'foto' => 'foto-pengguna/baru.png',
            'no_hp' => '081234567890',
        ]);
        $activity = Activity::query()->where('event', 'diubah')->sole();

        $this->actingAs($superAdmin);

        Livewire::test(ListActivityLogs::class)
            ->assertTableColumnStateSet('objek', 'siti', $activity)
            ->assertTableColumnStateSet('rincian', 'no_hp: — → 6281234567890; NIK diubah; foto diganti', $activity)
            ->assertDontSee('7404012345670001')
            ->assertDontSee('7404012345670002')
            ->assertDontSee('foto-pengguna/');
    }

    public function test_nik_and_photo_markers_apply_only_to_the_user_log(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        activity('lain')
            ->causedBy($superAdmin)
            ->event('diubah')
            ->withChanges(['attributes' => ['foto' => 'galeri/a.png']])
            ->log('Lain diubah');
        $activity = Activity::query()->sole();

        $this->actingAs($superAdmin);

        Livewire::test(ListActivityLogs::class)
            ->assertTableColumnStateSet('rincian', 'foto: galeri/a.png', $activity);
    }

    public function test_searching_a_nik_shows_in_the_log_without_the_nik(): void
    {
        $superAdmin = User::factory()->superAdmin()->create(['name' => 'Kepala TU']);
        $user = User::factory()->operator()->create(['username' => 'siti']);
        $user->isiNik('7404012345670001');
        $user->save();
        app(UserService::class)->cariNik($superAdmin, '7404012345670001');
        $activity = Activity::query()->sole();

        $this->actingAs($superAdmin);

        Livewire::test(ListActivityLogs::class)
            ->assertTableColumnFormattedStateSet('event', 'NIK dicari', $activity)
            ->assertTableColumnStateSet('objek', 'siti', $activity)
            ->assertDontSee('7404012345670001');
    }

    public function test_revealing_a_nik_shows_who_saw_whose_nik(): void
    {
        $superAdmin = User::factory()->superAdmin()->create(['name' => 'Kepala TU']);
        $user = User::factory()->operator()->create(['username' => 'siti']);
        $user->isiNik('7404012345670001');
        $user->save();
        app(UserService::class)->tampilkanNik($superAdmin, $user);
        $activity = Activity::query()->sole();

        $this->actingAs($superAdmin);

        Livewire::test(ListActivityLogs::class)
            ->assertTableColumnStateSet('causer.name', 'Kepala TU', $activity)
            ->assertTableColumnFormattedStateSet('event', 'NIK ditampilkan', $activity)
            ->assertTableColumnStateSet('objek', 'siti', $activity)
            ->assertTableColumnStateSet('rincian', null, $activity)
            ->assertDontSee('7404012345670001');
    }

    public function test_entry_without_actor_is_shown_as_sistem(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $activity = activity('pengguna')->causedByAnonymous()->event('dibuat')->log('Pengguna dibuat');

        $this->actingAs($superAdmin);

        Livewire::test(ListActivityLogs::class)
            ->assertTableColumnStateSet('causer.name', 'Sistem', $activity);
    }

    public function test_filters_by_actor(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $otherSuperAdmin = User::factory()->superAdmin()->create();
        $operator = User::factory()->operator()->create();
        app(UserService::class)->deactivate($superAdmin, $operator);
        app(UserService::class)->activate($otherSuperAdmin, $operator);
        $bySuperAdmin = Activity::query()->where('event', 'dinonaktifkan')->sole();
        $byOther = Activity::query()->where('event', 'diaktifkan')->sole();

        $this->actingAs($superAdmin);

        Livewire::test(ListActivityLogs::class)
            ->filterTable('pelaku', $superAdmin->id)
            ->assertCanSeeTableRecords([$bySuperAdmin])
            ->assertCanNotSeeTableRecords([$byOther]);
    }

    public function test_filters_by_date_range(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $operator = User::factory()->operator()->create();

        $this->travelTo('2026-11-01 09:00:00');
        app(UserService::class)->deactivate($superAdmin, $operator);
        $this->travelTo('2026-11-10 09:00:00');
        app(UserService::class)->activate($superAdmin, $operator);
        $this->travelBack();

        $early = Activity::query()->where('event', 'dinonaktifkan')->sole();
        $late = Activity::query()->where('event', 'diaktifkan')->sole();

        $this->actingAs($superAdmin);

        Livewire::test(ListActivityLogs::class)
            ->filterTable('waktu', ['dari' => '2026-11-05', 'sampai' => '2026-11-12'])
            ->assertCanSeeTableRecords([$late])
            ->assertCanNotSeeTableRecords([$early]);
    }

    public function test_date_filter_includes_entries_on_the_until_date(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $operator = User::factory()->operator()->create();

        $this->travelTo('2026-11-12 23:00:00');
        app(UserService::class)->deactivate($superAdmin, $operator);
        $this->travelTo('2026-11-13 00:30:00');
        app(UserService::class)->activate($superAdmin, $operator);
        $this->travelBack();

        $onUntilDate = Activity::query()->where('event', 'dinonaktifkan')->sole();
        $dayAfter = Activity::query()->where('event', 'diaktifkan')->sole();

        $this->actingAs($superAdmin);

        Livewire::test(ListActivityLogs::class)
            ->filterTable('waktu', ['dari' => '2026-11-12', 'sampai' => '2026-11-12'])
            ->assertCanSeeTableRecords([$onUntilDate])
            ->assertCanNotSeeTableRecords([$dayAfter]);
    }

    public function test_date_filter_shows_active_indicators(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(ListActivityLogs::class)
            ->filterTable('waktu', ['dari' => '2026-11-05', 'sampai' => '2026-11-12'])
            ->assertSeeText('Dari 05 Nov 2026')
            ->assertSeeText('Sampai 12 Nov 2026');
    }

    public function test_is_read_only_even_for_super_admin(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $operator = User::factory()->operator()->create();
        app(UserService::class)->deactivate($superAdmin, $operator);
        $activity = Activity::query()->sole();

        $this->actingAs($superAdmin);

        Livewire::test(ListActivityLogs::class)
            ->assertActionDoesNotExist('create')
            ->assertActionDoesNotExist(TestAction::make('edit')->table($activity))
            ->assertActionDoesNotExist(TestAction::make('delete')->table($activity))
            ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());

        $this->assertSame(['index'], array_keys(ActivityLogResource::getPages()));
    }

    public function test_a_removed_panel_role_shows_as_no_panel_access(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $operator = User::factory()->operator()->create();
        app(UserService::class)->update($superAdmin, $operator, [
            'name' => $operator->name,
            'username' => $operator->username,
            'email' => $operator->email,
            'peran' => null,
        ]);
        $log = Activity::query()->where('event', 'role_berubah')->sole();

        $this->actingAs($superAdmin);

        Livewire::test(ListActivityLogs::class)
            ->assertTableColumnStateSet('rincian', 'peran: operator → tanpa akses panel', $log);
    }

    public function test_employment_status_entries_name_the_status(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $gtt = StatusKepegawaian::query()->where('nama', 'GTT')->sole();
        app(StatusKepegawaianService::class)->deactivate($superAdmin, $gtt);
        $log = Activity::query()->where('event', 'status_kepegawaian_dinonaktifkan')->sole();

        $this->actingAs($superAdmin);

        Livewire::test(ListActivityLogs::class)
            ->assertTableColumnStateSet('objek', 'GTT', $log)
            ->assertTableColumnFormattedStateSet('event', 'Status kepegawaian dinonaktifkan', $log)
            ->assertTableColumnStateSet('rincian', 'status_aktif: aktif → nonaktif', $log);
    }

    public function test_staff_entries_name_the_staff_member_with_titles(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $ptk = Ptk::factory()
            ->for(User::factory()->state(['name' => 'Hafida']))
            ->create(['gelar_depan' => 'Dra.', 'gelar_belakang' => 'S.Pd.']);
        app(PtkService::class)->ubahStatus($superAdmin, $ptk, [
            'status' => 'pensiun',
            'tanggal_keluar' => '2026-01-31',
            'keterangan_keluar' => 'Pensiun usia',
        ]);
        $log = Activity::query()->where('event', 'ptk_status_diubah')->sole();

        $this->actingAs($superAdmin);

        Livewire::test(ListActivityLogs::class)
            ->assertTableColumnStateSet('objek', 'Dra. Hafida, S.Pd.', $log)
            ->assertTableColumnFormattedStateSet('event', 'Status guru/staf diubah', $log)
            ->assertTableColumnStateSet('rincian', 'status: aktif → pensiun; tanggal_keluar: 2026-01-31; keterangan_keluar: Pensiun usia', $log);
    }

    public function test_student_entries_name_the_student_and_the_nis(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $siswa = Siswa::factory()->for(User::factory()->state(['name' => 'Budi']))->create(['nis' => '20261001']);
        app(SiswaService::class)->ubahStatus($superAdmin, $siswa, [
            'status' => 'lulus',
            'tanggal_keluar' => '2026-06-20',
            'keterangan_keluar' => 'Lulus',
        ]);
        $log = Activity::query()->where('event', 'siswa_status_diubah')->sole();

        $this->actingAs($superAdmin);

        Livewire::test(ListActivityLogs::class)
            ->assertTableColumnStateSet('objek', 'Budi (20261001)', $log)
            ->assertTableColumnFormattedStateSet('event', 'Status siswa diubah', $log);
    }
}
