<?php

namespace Tests\Feature\Filament\Resources\Ptks;

use App\Domains\Foundation\Models\User;
use App\Domains\People\Enums\StatusPtk;
use App\Domains\People\Models\Ptk;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Models\StatusKepegawaian;
use App\Domains\People\Models\WaliMurid;
use App\Domains\People\Services\StatusKepegawaianService;
use App\Filament\Resources\Ptks\Pages\CreatePtk;
use App\Filament\Resources\Ptks\Pages\EditPtk;
use App\Filament\Resources\Ptks\Pages\ListPtks;
use App\Filament\Resources\Ptks\Pages\ViewPtk;
use App\Filament\Resources\Ptks\PtkResource;
use App\Filament\Resources\Users\Schemas\UserForm;
use Database\Factories\UserFactory;
use Database\Seeders\PeranDanIzinSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PtkResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string NIK = '7404012345670001';

    private const string NIK_TERSAMAR = '7404********0001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        Filament::setCurrentPanel('admin');
    }

    public function test_operator_opens_the_list_with_full_name_kind_status_and_code(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $ptk = Ptk::factory()
            ->for(User::factory()->state(['name' => 'Hafida']))
            ->create(['gelar_depan' => 'Dra.', 'gelar_belakang' => 'S.Pd., Gr.', 'kode' => 'G01']);

        $this->get(PtkResource::getUrl('index'))->assertOk()->assertSee('Guru dan Staf');

        Livewire::test(ListPtks::class)
            ->assertCanSeeTableRecords([$ptk])
            ->assertTableColumnStateSet('nama_lengkap', 'Dra. Hafida, S.Pd., Gr.', $ptk)
            ->assertTableColumnFormattedStateSet('jenis_ptk', 'Guru', $ptk)
            ->assertTableColumnStateSet('statusKepegawaian.nama', 'PNS', $ptk)
            ->assertTableColumnStateSet('kode', 'G01', $ptk)
            ->assertTableColumnFormattedStateSet('status', 'Aktif', $ptk);
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function deniedRoles(): array
    {
        return [
            'guru' => ['guru'],
            'tanpa role' => [null],
        ];
    }

    #[DataProvider('deniedRoles')]
    public function test_other_roles_cannot_open_the_screen(?string $role): void
    {
        $user = User::factory()->create();

        if ($role !== null) {
            $user->assignRole($role);
        }

        $this->actingAs($user)->get(PtkResource::getUrl('index'))->assertForbidden();
    }

    public function test_list_is_ordered_by_name_and_searched_by_name_nip_and_code(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $siti = Ptk::factory()->for(User::factory()->state(['name' => 'Siti Aminah']))->create(['nip' => '198001012005012001']);
        $budi = Ptk::factory()->for(User::factory()->state(['name' => 'Budi Santoso']))->create(['kode' => 'BS7']);

        Livewire::test(ListPtks::class)
            ->assertCanSeeTableRecords([$budi, $siti], inOrder: true)
            ->searchTable('Siti')
            ->assertCanSeeTableRecords([$siti])
            ->assertCanNotSeeTableRecords([$budi])
            ->searchTable('198001012005012001')
            ->assertCanSeeTableRecords([$siti])
            ->assertCanNotSeeTableRecords([$budi])
            ->searchTable('bs7')
            ->assertCanSeeTableRecords([$budi])
            ->assertCanNotSeeTableRecords([$siti]);
    }

    public function test_list_is_filtered_by_kind_employment_status_status_and_certification(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $pppk = StatusKepegawaian::query()->where('nama', 'PPPK')->sole();
        $guru = Ptk::factory()->create(['sertifikasi' => true, 'tahun_sertifikasi' => 2015]);
        $tendik = Ptk::factory()->tendik()->create(['status_kepegawaian_id' => $pppk->id]);
        $pensiun = Ptk::factory()->create(['status' => StatusPtk::Pensiun, 'tanggal_keluar' => '2026-01-31', 'keterangan_keluar' => 'Pensiun']);

        Livewire::test(ListPtks::class)
            ->filterTable('jenis_ptk', 'tendik')
            ->assertCanSeeTableRecords([$tendik])
            ->assertCanNotSeeTableRecords([$guru, $pensiun])
            ->resetTableFilters()
            ->filterTable('status_kepegawaian_id', $pppk->id)
            ->assertCanSeeTableRecords([$tendik])
            ->assertCanNotSeeTableRecords([$guru, $pensiun])
            ->resetTableFilters()
            ->filterTable('status', 'pensiun')
            ->assertCanSeeTableRecords([$pensiun])
            ->assertCanNotSeeTableRecords([$guru, $tendik])
            ->resetTableFilters()
            ->filterTable('sertifikasi', true)
            ->assertCanSeeTableRecords([$guru])
            ->assertCanNotSeeTableRecords([$tendik, $pensiun]);
    }

    public function test_operator_creates_a_guru_with_a_new_account_through_the_form(): void
    {
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(CreatePtk::class)
            ->fillForm([
                'akun' => 'baru',
                'name' => 'Hafida',
                'username' => 'hafida',
                'nik' => self::NIK,
                'jenis_kelamin' => 'perempuan',
                'jenis_ptk' => 'guru',
                'status_kepegawaian_id' => StatusKepegawaian::query()->where('nama', 'PNS')->value('id'),
                'gelar_depan' => 'Dra.',
                'kode' => 'g01',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $ptk = Ptk::query()->sole();
        $this->assertSame('Dra. Hafida', $ptk->nama_lengkap);
        $this->assertSame('G01', $ptk->kode);
        $this->assertSame(self::NIK, $ptk->user->nik);
        $this->assertSame(['guru'], $ptk->user->getRoleNames()->all());
    }

    public function test_service_errors_land_on_the_form_fields(): void
    {
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(CreatePtk::class)
            ->fillForm([
                'akun' => 'baru',
                'name' => 'Hafida',
                'username' => 'hafida',
                'jenis_ptk' => 'guru',
                'status_kepegawaian_id' => StatusKepegawaian::query()->where('nama', 'PNS')->value('id'),
                'jenis_kelamin' => 'perempuan',
                'nip' => '123',
            ])
            ->call('create')
            ->assertHasFormErrors(['nip']);

        $this->assertSame(0, Ptk::query()->count());
    }

    public function test_choosing_an_existing_account_fills_its_data_and_links_it(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $operator = User::factory()->operator()->create(['name' => 'Siti', 'username' => 'siti', 'tempat_lahir' => 'Kendari']);

        Livewire::test(CreatePtk::class)
            ->fillForm(['akun' => 'ada'])
            ->fillForm(['user_id' => $operator->id])
            ->assertSchemaStateSet(['name' => 'Siti', 'username' => 'siti', 'tempat_lahir' => 'Kendari'])
            ->fillForm([
                'jenis_kelamin' => 'perempuan',
                'jenis_ptk' => 'tendik',
                'status_kepegawaian_id' => StatusKepegawaian::query()->where('nama', 'PNS')->value('id'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(Ptk::query()->sole()->user->is($operator));
        $this->assertEqualsCanonicalizing(['operator', 'tendik'], $operator->fresh()->getRoleNames()->all());
    }

    public function test_the_account_search_offers_only_accounts_the_operator_may_link(): void
    {
        $this->actingAs(User::factory()->operator()->create(['name' => 'Saya Operator', 'username' => 'saya.operator']));
        User::factory()->create(['name' => 'Sari Bebas', 'username' => 'sari']);
        User::factory()->operator()->create(['name' => 'Sari Operator']);
        User::factory()->superAdmin()->create(['name' => 'Sari Admin']);
        Ptk::factory()->for(User::factory()->state(['name' => 'Sari Guru']))->create();
        Siswa::factory()->for(User::factory()->state(['name' => 'Sari Siswa']))->create();
        WaliMurid::factory()->for(User::factory()->state(['name' => 'Sari Wali']))->create();

        Livewire::test(CreatePtk::class)
            ->fillForm(['akun' => 'ada'])
            ->assertFormFieldExists('user_id', fn (Select $field): bool => array_values($field->getSearchResults('Sari')) === ['Sari Bebas (sari)']);
    }

    public function test_a_chosen_student_account_fills_nothing_and_shows_no_label(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $siswa = Siswa::factory()->for(User::factory()->state(['name' => 'Budi Siswa', 'tempat_lahir' => 'Kendari']))->create();

        Livewire::test(CreatePtk::class)
            ->fillForm(['akun' => 'ada'])
            ->fillForm(['user_id' => $siswa->user_id])
            ->assertSchemaStateSet(['name' => null, 'tempat_lahir' => null])
            ->assertFormFieldExists('user_id', fn (Select $field): bool => $field->getOptionLabel(withDefault: false) === null);
    }

    public function test_an_inactive_employment_status_is_not_offered_for_a_new_staff_member(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($superAdmin);
        app(StatusKepegawaianService::class)->deactivate($superAdmin, StatusKepegawaian::query()->where('nama', 'GTT')->sole());

        Livewire::test(CreatePtk::class)
            ->assertFormFieldExists('status_kepegawaian_id', fn (Select $field): bool => array_values($field->getOptions()) === ['PNS', 'PPPK', 'PTT', 'GTBPNS']);
    }

    public function test_edit_form_is_filled_from_the_account_and_saves_both(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $ptk = Ptk::factory()
            ->for(User::factory()->state(['name' => 'Hafida', 'tempat_lahir' => 'Kendari', 'jenis_kelamin' => 'perempuan']))
            ->create(['kode' => 'G01']);

        Livewire::test(EditPtk::class, ['record' => $ptk->getRouteKey()])
            ->assertSchemaStateSet(['name' => 'Hafida', 'tempat_lahir' => 'Kendari', 'jenis_kelamin' => 'perempuan', 'kode' => 'G01', 'nik' => null])
            ->assertFormFieldDoesNotExist('status')
            ->fillForm(['tempat_lahir' => 'Baubau', 'gelar_belakang' => 'S.Pd.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $ptk = $ptk->fresh();
        $this->assertSame('Baubau', $ptk->user->tempat_lahir);
        $this->assertSame('Hafida, S.Pd.', $ptk->nama_lengkap);
    }

    public function test_operator_changes_the_status_and_reactivates_through_the_actions(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $ptk = Ptk::factory()->for(User::factory()->state(['name' => 'Hafida']))->create();

        Livewire::test(ListPtks::class)
            ->assertActionHidden(TestAction::make('aktifkanKembali')->table($ptk))
            ->callAction(TestAction::make('ubahStatus')->table($ptk), data: [
                'status' => 'pensiun',
                'tanggal_keluar' => now()->toDateString(),
                'keterangan_keluar' => 'Pensiun usia',
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Status Hafida diubah menjadi Pensiun');

        $this->assertSame(StatusPtk::Pensiun, $ptk->fresh()->status);
        $this->assertFalse($ptk->user->fresh()->status_aktif);

        Livewire::test(ListPtks::class)
            ->assertActionHidden(TestAction::make('ubahStatus')->table($ptk->fresh()))
            ->callAction(TestAction::make('aktifkanKembali')->table($ptk->fresh()))
            ->assertNotified('Hafida aktif kembali');

        $this->assertSame(StatusPtk::Aktif, $ptk->fresh()->status);
        $this->assertTrue($ptk->user->fresh()->status_aktif);
    }

    public function test_status_errors_land_on_the_action_fields(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $ptk = Ptk::factory()->create();

        Livewire::test(ListPtks::class)
            ->callAction(TestAction::make('ubahStatus')->table($ptk), data: [
                'status' => 'mutasi',
                'tanggal_keluar' => now()->addDay()->toDateString(),
                'keterangan_keluar' => 'Pindah',
            ])
            ->assertHasFormErrors(['tanggal_keluar']);

        $this->assertSame(StatusPtk::Aktif, $ptk->fresh()->status);
    }

    public function test_operator_only_views_the_staff_record_of_another_operator(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $ptk = Ptk::factory()->for(User::factory()->operator()->state(['name' => 'Siti']))->create();

        Livewire::test(ListPtks::class)
            ->assertActionVisible(TestAction::make('view')->table($ptk))
            ->assertActionHidden(TestAction::make('edit')->table($ptk))
            ->assertActionHidden(TestAction::make('ubahStatus')->table($ptk));

        $this->get(PtkResource::getUrl('edit', ['record' => $ptk]))->assertForbidden();

        Livewire::test(ViewPtk::class, ['record' => $ptk->getRouteKey()])
            ->assertOk()
            ->assertSchemaStateSet(['name' => 'Siti']);
    }

    public function test_offers_no_delete_action_even_to_super_admin(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $ptk = Ptk::factory()->create();

        Livewire::test(ListPtks::class)
            ->assertActionDoesNotExist(TestAction::make('delete')->table($ptk))
            ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());

        Livewire::test(EditPtk::class, ['record' => $ptk->getRouteKey()])
            ->assertActionDoesNotExist('delete');
    }

    public function test_the_view_page_hides_the_masked_nik_of_an_account_the_operator_may_not_reveal(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $ptk = Ptk::factory()->for($this->userWithNik(User::factory()->operator()))->create();

        Livewire::test(ViewPtk::class, ['record' => $ptk->getRouteKey()])
            ->assertFormFieldExists('nik', fn (TextInput $field): bool => $field->getPlaceholder() === null)
            ->assertFormFieldHidden('hapus_nik')
            ->assertSee(UserForm::KETERANGAN_NIK)
            ->assertDontSee('Kosongkan untuk tetap memakai NIK tersimpan.')
            ->assertDontSeeHtml(self::NIK_TERSAMAR);
    }

    public function test_the_edit_page_shows_the_masked_nik_to_who_may_reveal_it(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $ptk = Ptk::factory()->for($this->userWithNik(User::factory()))->create();

        Livewire::test(EditPtk::class, ['record' => $ptk->getRouteKey()])
            ->assertFormFieldExists('nik', fn (TextInput $field): bool => $field->getPlaceholder() === self::NIK_TERSAMAR)
            ->assertSee('Kosongkan untuk tetap memakai NIK tersimpan.')
            ->assertFormFieldVisible('hapus_nik');
    }

    public function test_without_view_nik_permission_the_edit_page_shows_no_masked_nik(): void
    {
        Role::findByName('operator', 'web')->revokePermissionTo('ViewNik:User');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(User::factory()->operator()->create());
        $ptk = Ptk::factory()->for($this->userWithNik(User::factory()))->create();

        Livewire::test(EditPtk::class, ['record' => $ptk->getRouteKey()])
            ->assertFormFieldExists('nik', fn (TextInput $field): bool => $field->getPlaceholder() === null)
            ->assertFormFieldHidden('hapus_nik')
            ->assertDontSeeHtml(self::NIK_TERSAMAR);
    }

    public function test_choosing_an_account_shows_its_masked_nik(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $user = $this->userWithNik(User::factory());

        Livewire::test(CreatePtk::class)
            ->fillForm(['akun' => 'ada'])
            ->fillForm(['user_id' => $user->id])
            ->assertFormFieldExists('nik', fn (TextInput $field): bool => $field->getPlaceholder() === self::NIK_TERSAMAR);
    }

    public function test_changing_the_account_choice_empties_the_copied_data(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $user = User::factory()->create(['name' => 'Budi', 'username' => 'budi', 'tempat_lahir' => 'Kendari']);

        Livewire::test(CreatePtk::class)
            ->fillForm(['akun' => 'ada'])
            ->fillForm(['user_id' => $user->id])
            ->assertSchemaStateSet(['name' => 'Budi'])
            ->fillForm(['user_id' => null])
            ->assertSchemaStateSet(['name' => null, 'username' => null, 'tempat_lahir' => null])
            ->fillForm(['user_id' => $user->id])
            ->fillForm(['akun' => 'baru'])
            ->assertSchemaStateSet(['user_id' => null, 'name' => null, 'username' => null, 'tempat_lahir' => null]);
    }

    public function test_a_tampered_account_choice_reveals_nothing(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $superAdmin = User::factory()->superAdmin()->create(['name' => 'Kepala TU', 'tempat_lahir' => 'Kendari']);

        Livewire::test(CreatePtk::class)
            ->fillForm(['akun' => 'ada'])
            ->fillForm(['user_id' => $superAdmin->id])
            ->assertSchemaStateSet(['name' => null, 'tempat_lahir' => null])
            ->assertFormFieldExists('user_id', fn (Select $field): bool => $field->getOptionLabel(withDefault: false) === null)
            ->assertDontSeeHtml('Kepala TU');
    }

    public function test_the_account_search_is_not_cut_short_by_accounts_the_operator_may_not_link(): void
    {
        $this->actingAs(User::factory()->operator()->create(['name' => 'Operator Uji', 'username' => 'operator.uji']));
        User::factory()->count(51)->operator()->sequence(fn ($sequence): array => ['name' => 'Sari Operator '.str_pad((string) $sequence->index, 2, '0', STR_PAD_LEFT)])->create();
        User::factory()->create(['name' => 'Sari Zahra', 'username' => 'zahra']);

        Livewire::test(CreatePtk::class)
            ->fillForm(['akun' => 'ada'])
            ->assertFormFieldExists('user_id', fn (Select $field): bool => array_values($field->getSearchResults('Sari')) === ['Sari Zahra (zahra)']);
    }

    public function test_the_account_search_matches_percent_and_underscore_literally(): void
    {
        $this->actingAs(User::factory()->operator()->create(['name' => 'Operator', 'username' => 'operator']));
        User::factory()->create(['name' => 'Budi Santoso', 'username' => 'budi']);
        User::factory()->create(['name' => 'Siti 100%', 'username' => 'siti']);

        Livewire::test(CreatePtk::class)
            ->fillForm(['akun' => 'ada'])
            ->assertFormFieldExists('user_id', fn (Select $field): bool => array_values($field->getSearchResults('%')) === ['Siti 100% (siti)']
                && $field->getSearchResults('_') === []);
    }

    public function test_reveal_nik_on_the_edit_page_is_logged(): void
    {
        $operator = User::factory()->operator()->create();
        $this->actingAs($operator);
        $user = User::factory()->create();
        $user->isiNik(self::NIK);
        $user->save();
        $ptk = Ptk::factory()->for($user)->create();

        Livewire::test(EditPtk::class, ['record' => $ptk->getRouteKey()])
            ->callAction('tampilkanNik')
            ->assertSet('nikTerbuka', self::NIK);

        $this->assertDatabaseHas('activity_log', ['event' => 'nik_ditampilkan', 'subject_id' => $user->id]);
    }

    private function userWithNik(UserFactory $factory): User
    {
        $user = $factory->create();
        $user->isiNik(self::NIK);
        $user->save();

        return $user;
    }
}
