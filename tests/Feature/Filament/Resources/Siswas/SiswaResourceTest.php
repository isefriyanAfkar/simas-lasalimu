<?php

namespace Tests\Feature\Filament\Resources\Siswas;

use App\Domains\Foundation\Models\User;
use App\Domains\People\Enums\StatusSiswa;
use App\Domains\People\Models\Siswa;
use App\Filament\Resources\Siswas\Pages\CreateSiswa;
use App\Filament\Resources\Siswas\Pages\EditSiswa;
use App\Filament\Resources\Siswas\Pages\ListSiswas;
use App\Filament\Resources\Siswas\SiswaResource;
use App\Filament\Resources\Users\Schemas\UserForm;
use Database\Seeders\PeranDanIzinSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SiswaResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string NIK = '7404012345670002';

    private const string NIK_TERSAMAR = '7404********0002';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        Filament::setCurrentPanel('admin');
    }

    public function test_operator_opens_the_list_with_nis_name_nisn_and_status(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $siswa = Siswa::factory()->for(User::factory()->state(['name' => 'Budi']))->create(['nis' => '20261001', 'nisn' => '0081234567']);

        $this->get(SiswaResource::getUrl('index'))->assertOk()->assertSee('Siswa');

        Livewire::test(ListSiswas::class)
            ->assertCanSeeTableRecords([$siswa])
            ->assertTableColumnStateSet('nis', '20261001', $siswa)
            ->assertTableColumnStateSet('user.name', 'Budi', $siswa)
            ->assertTableColumnStateSet('nisn', '0081234567', $siswa)
            ->assertTableColumnFormattedStateSet('status', 'Aktif', $siswa);
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function deniedRoles(): array
    {
        return [
            'guru' => ['guru'],
            'siswa' => ['siswa'],
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

        $this->actingAs($user)->get(SiswaResource::getUrl('index'))->assertForbidden();
    }

    public function test_list_is_searched_by_name_nis_and_nisn(): void
    {
        $this->actingAs(User::factory()->operator()->create(['name' => 'Operator', 'username' => 'operator']));
        $ani = Siswa::factory()->for(User::factory()->state(['name' => 'Ani Lestari']))->create(['nis' => '20261001', 'nisn' => '0081111111']);
        $budi = Siswa::factory()->for(User::factory()->state(['name' => 'Budi Santoso']))->create(['nis' => '20261002', 'nisn' => '0082222222']);

        Livewire::test(ListSiswas::class)
            ->assertCanSeeTableRecords([$ani, $budi], inOrder: true)
            ->searchTable('Budi')
            ->assertCanSeeTableRecords([$budi])
            ->assertCanNotSeeTableRecords([$ani])
            ->searchTable('20261001')
            ->assertCanSeeTableRecords([$ani])
            ->assertCanNotSeeTableRecords([$budi])
            ->searchTable('0082222222')
            ->assertCanSeeTableRecords([$budi])
            ->assertCanNotSeeTableRecords([$ani]);
    }

    public function test_list_is_filtered_by_status(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $aktif = Siswa::factory()->create();
        $lulus = Siswa::factory()->create(['status' => StatusSiswa::Lulus, 'tanggal_keluar' => '2026-06-20', 'keterangan_keluar' => 'Lulus']);

        Livewire::test(ListSiswas::class)
            ->filterTable('status', 'lulus')
            ->assertCanSeeTableRecords([$lulus])
            ->assertCanNotSeeTableRecords([$aktif]);
    }

    public function test_operator_creates_a_student_whose_username_is_the_nis(): void
    {
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(CreateSiswa::class)
            ->assertFormFieldDoesNotExist('username')
            ->fillForm([
                'name' => 'Budi',
                'nis' => '20261001',
                'nisn' => '0081234567',
                'nik' => self::NIK,
                'jenis_kelamin' => 'laki-laki',
                'tanggal_masuk' => '2026-07-13',
                'asal_sekolah' => 'SMP Negeri 1',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $siswa = Siswa::query()->sole();
        $this->assertSame('20261001', $siswa->user->username);
        $this->assertSame(self::NIK, $siswa->user->nik);
        $this->assertSame(['siswa'], $siswa->user->getRoleNames()->all());
        $this->assertSame('SMP Negeri 1', $siswa->asal_sekolah);
    }

    public function test_service_errors_land_on_the_form_fields(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        User::factory()->create(['username' => '20261001']);

        Livewire::test(CreateSiswa::class)
            ->fillForm([
                'name' => 'Budi',
                'nis' => '20261001',
                'nisn' => '123',
                'jenis_kelamin' => 'laki-laki',
            ])
            ->call('create')
            ->assertHasFormErrors(['nis', 'nisn']);

        $this->assertSame(0, Siswa::query()->count());
    }

    public function test_edit_form_is_filled_from_the_account_and_a_new_nis_changes_the_username(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $siswa = Siswa::factory()
            ->for(User::factory()->state(['name' => 'Budi', 'tempat_lahir' => 'Kendari', 'jenis_kelamin' => 'laki-laki']))
            ->create(['nis' => '20261001', 'tanggal_masuk' => '2026-07-13']);

        Livewire::test(EditSiswa::class, ['record' => $siswa->getRouteKey()])
            ->assertSchemaStateSet(['name' => 'Budi', 'nis' => '20261001', 'tempat_lahir' => 'Kendari', 'jenis_kelamin' => 'laki-laki', 'tanggal_masuk' => '2026-07-13', 'nik' => null])
            ->assertFormFieldDoesNotExist('status')
            ->fillForm(['tempat_lahir' => 'Baubau', 'nis' => '20269999'])
            ->call('save')
            ->assertHasNoFormErrors();

        $siswa = $siswa->fresh();
        $this->assertSame('Baubau', $siswa->user->tempat_lahir);
        $this->assertSame('20269999', $siswa->user->username);
    }

    public function test_operator_changes_the_status_and_reactivates_through_the_actions(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $siswa = Siswa::factory()->for(User::factory()->state(['name' => 'Budi']))->create();

        Livewire::test(ListSiswas::class)
            ->assertActionHidden(TestAction::make('aktifkanKembali')->table($siswa))
            ->callAction(TestAction::make('ubahStatus')->table($siswa), data: [
                'status' => 'mutasi_keluar',
                'tanggal_keluar' => now()->toDateString(),
                'keterangan_keluar' => 'Pindah ke Makassar',
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Status Budi diubah menjadi Mutasi keluar');

        $this->assertSame(StatusSiswa::MutasiKeluar, $siswa->fresh()->status);
        $this->assertFalse($siswa->user->fresh()->status_aktif);

        Livewire::test(ListSiswas::class)
            ->assertActionHidden(TestAction::make('ubahStatus')->table($siswa->fresh()))
            ->callAction(TestAction::make('aktifkanKembali')->table($siswa->fresh()))
            ->assertNotified('Budi aktif kembali');

        $this->assertSame(StatusSiswa::Aktif, $siswa->fresh()->status);
        $this->assertTrue($siswa->user->fresh()->status_aktif);
    }

    public function test_status_errors_land_on_the_action_fields(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $siswa = Siswa::factory()->create();

        Livewire::test(ListSiswas::class)
            ->callAction(TestAction::make('ubahStatus')->table($siswa), data: [
                'status' => 'keluar',
                'tanggal_keluar' => now()->addDay()->toDateString(),
                'keterangan_keluar' => 'Keluar',
            ])
            ->assertHasFormErrors(['tanggal_keluar']);

        $this->assertSame(StatusSiswa::Aktif, $siswa->fresh()->status);
    }

    public function test_offers_no_delete_action_even_to_super_admin(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $siswa = Siswa::factory()->create();

        Livewire::test(ListSiswas::class)
            ->assertActionDoesNotExist(TestAction::make('delete')->table($siswa))
            ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());

        Livewire::test(EditSiswa::class, ['record' => $siswa->getRouteKey()])
            ->assertActionDoesNotExist('delete');
    }

    public function test_the_edit_page_shows_the_masked_nik_to_who_may_reveal_it(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $siswa = $this->siswaWithNik();

        Livewire::test(EditSiswa::class, ['record' => $siswa->getRouteKey()])
            ->assertFormFieldExists('nik', fn (TextInput $field): bool => $field->getPlaceholder() === self::NIK_TERSAMAR)
            ->assertSee('Kosongkan untuk tetap memakai NIK tersimpan.')
            ->assertFormFieldVisible('hapus_nik');
    }

    public function test_without_view_nik_permission_the_edit_page_shows_no_masked_nik(): void
    {
        Role::findByName('operator', 'web')->revokePermissionTo('ViewNik:User');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(User::factory()->operator()->create());
        $siswa = $this->siswaWithNik();

        Livewire::test(EditSiswa::class, ['record' => $siswa->getRouteKey()])
            ->assertFormFieldExists('nik', fn (TextInput $field): bool => $field->getPlaceholder() === null)
            ->assertFormFieldHidden('hapus_nik')
            ->assertSee(UserForm::KETERANGAN_NIK)
            ->assertDontSee('Kosongkan untuk tetap memakai NIK tersimpan.')
            ->assertDontSeeHtml(self::NIK_TERSAMAR);
    }

    public function test_reveal_nik_on_the_edit_page_is_logged(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $siswa = $this->siswaWithNik();

        Livewire::test(EditSiswa::class, ['record' => $siswa->getRouteKey()])
            ->callAction('tampilkanNik')
            ->assertSet('nikTerbuka', self::NIK);

        $this->assertDatabaseHas('activity_log', ['event' => 'nik_ditampilkan', 'subject_id' => $siswa->user_id]);
    }

    private function siswaWithNik(): Siswa
    {
        $siswa = Siswa::factory()->create();
        $siswa->user->isiNik(self::NIK);
        $siswa->user->save();

        return $siswa;
    }
}
