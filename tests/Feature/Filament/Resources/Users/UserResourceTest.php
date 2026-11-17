<?php

namespace Tests\Feature\Filament\Resources\Users;

use App\Domains\Foundation\Enums\Peran;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Models\Siswa;
use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use Database\Seeders\PeranDanIzinSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class UserResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        Filament::setCurrentPanel('admin');
    }

    public function test_super_admin_sees_users_in_the_list(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $operator = User::factory()->operator()->create();

        $this->actingAs($superAdmin);

        Livewire::test(ListUsers::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$superAdmin, $operator]);
    }

    public function test_student_accounts_are_hidden_by_default_and_shown_by_the_filter(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $guru = User::factory()->create();
        $siswa = Siswa::factory()->create();

        $this->actingAs($superAdmin);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$superAdmin, $guru])
            ->assertCanNotSeeTableRecords([$siswa->user])
            ->filterTable('akun_siswa', true)
            ->assertCanSeeTableRecords([$siswa->user])
            ->assertCanNotSeeTableRecords([$superAdmin, $guru])
            ->filterTable('akun_siswa', null)
            ->assertCanSeeTableRecords([$superAdmin, $guru, $siswa->user]);
    }

    public function test_operator_without_user_permission_gets_403_on_the_user_list(): void
    {
        $this->actingAs(User::factory()->operator()->create())
            ->get(UserResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_offers_no_delete_action_even_to_super_admin(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $user = User::factory()->operator()->create();

        $this->actingAs($superAdmin);

        Livewire::test(ListUsers::class)
            ->assertActionDoesNotExist(TestAction::make('delete')->table($user))
            ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertActionDoesNotExist('delete');
    }

    public function test_super_admin_creates_a_user_through_the_form(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Siti Aminah',
                'username' => 'siti',
                'peran' => Peran::Operator->value,
                'password' => 'rahasia-baru-123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('username', 'siti')->firstOrFail();
        $this->assertTrue($user->hasRole(Peran::Operator->value));
        $this->assertTrue($user->status_aktif);
    }

    public function test_create_form_rejects_a_taken_username_and_a_short_password(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        User::factory()->operator()->create(['username' => 'siti']);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Siti Lain',
                'username' => 'siti',
                'peran' => Peran::Operator->value,
                'password' => 'pendek',
            ])
            ->call('create')
            ->assertHasFormErrors(['username' => 'unique', 'password']);

        $this->assertSame(1, User::query()->where('username', 'siti')->count());
    }

    public function test_create_form_rejects_a_taken_username_written_with_spaces_and_other_case(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        User::factory()->operator()->create(['username' => 'siti']);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Siti Lain',
                'username' => ' SITI ',
                'peran' => Peran::Operator->value,
                'password' => 'rahasia-kuat-123',
            ])
            ->call('create')
            ->assertHasFormErrors(['username' => 'unique']);

        $this->assertSame(1, User::query()->where('username', 'siti')->count());
    }

    public function test_edit_form_rejects_the_username_of_another_user(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        User::factory()->operator()->create(['username' => 'siti']);
        $budi = User::factory()->operator()->create(['username' => 'budi']);

        Livewire::test(EditUser::class, ['record' => $budi->getRouteKey()])
            ->fillForm(['username' => 'Siti'])
            ->call('save')
            ->assertHasFormErrors(['username' => 'unique']);

        $this->assertSame('budi', $budi->fresh()->username);
    }

    public function test_edit_form_keeps_the_users_own_email(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $siti = User::factory()->operator()->create(['name' => 'Siti', 'email' => 'siti@sekolah.test']);

        Livewire::test(EditUser::class, ['record' => $siti->getRouteKey()])
            ->fillForm(['name' => 'Siti Aminah'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Siti Aminah', $siti->fresh()->name);
        $this->assertSame('siti@sekolah.test', $siti->fresh()->email);
    }

    public function test_edit_form_rejects_the_email_of_another_user(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        User::factory()->operator()->create(['email' => 'budi@sekolah.test']);
        $siti = User::factory()->operator()->create(['email' => 'siti@sekolah.test']);

        Livewire::test(EditUser::class, ['record' => $siti->getRouteKey()])
            ->fillForm(['email' => 'budi@sekolah.test'])
            ->call('save')
            ->assertHasFormErrors(['email' => 'unique']);

        $this->assertSame('siti@sekolah.test', $siti->fresh()->email);
    }

    /**
     * The form checks the same rules first, so the service only rejects on a
     * race; its errors must still land on the fields.
     */
    public function test_service_validation_errors_land_on_the_create_form_fields(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $this->partialMock(UserService::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('create')
            ->andThrow(ValidationException::withMessages(['username' => 'Username sudah dipakai.'])));

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Siti Aminah',
                'username' => 'siti',
                'peran' => Peran::Operator->value,
                'password' => 'rahasia-kuat-123',
            ])
            ->call('create')
            ->assertHasFormErrors(['username']);

        $this->assertSame(0, User::query()->where('username', 'siti')->count());
    }

    public function test_service_validation_errors_land_on_the_edit_form_fields(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $siti = User::factory()->operator()->create(['email' => 'siti@sekolah.test']);
        $this->partialMock(UserService::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('update')
            ->andThrow(ValidationException::withMessages(['email' => 'Email sudah dipakai.'])));

        Livewire::test(EditUser::class, ['record' => $siti->getRouteKey()])
            ->fillForm(['email' => 'siti.baru@sekolah.test'])
            ->call('save')
            ->assertHasFormErrors(['email']);

        $this->assertSame('siti@sekolah.test', $siti->fresh()->email);
    }

    public function test_edit_form_shows_rule_violation_and_keeps_the_record_unchanged(): void
    {
        $superAdmin = User::factory()->superAdmin()->create(['name' => 'Nama Lama']);
        $this->actingAs($superAdmin);

        Livewire::test(EditUser::class, ['record' => $superAdmin->getRouteKey()])
            ->fillForm(['name' => 'Nama Baru', 'peran' => Peran::Operator->value])
            ->call('save')
            ->assertNotified(PelanggaranAturanPengguna::superAdminAktifTerakhir()->getMessage());

        $superAdmin = $superAdmin->fresh();
        $this->assertSame('Nama Lama', $superAdmin->name);
        $this->assertTrue($superAdmin->hasRole(Peran::SuperAdmin->value));
    }

    public function test_deactivate_action_deactivates_the_user(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $operator = User::factory()->operator()->create();

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('nonaktifkan')->table($operator))
            ->assertNotified('Pengguna dinonaktifkan');

        $this->assertFalse($operator->fresh()->status_aktif);
    }

    public function test_deactivate_action_on_own_account_shows_the_rule_violation(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin);

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('nonaktifkan')->table($superAdmin))
            ->assertNotified(PelanggaranAturanPengguna::menonaktifkanDiriSendiri()->getMessage());

        $this->assertTrue($superAdmin->fresh()->status_aktif);
    }

    public function test_reset_password_action_sets_the_new_password(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $operator = User::factory()->operator()->create();

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('aturUlangKataSandi')->table($operator), data: [
                'password' => 'kata-sandi-baru-123',
                'password_confirmation' => 'kata-sandi-baru-123',
            ])
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('kata-sandi-baru-123', $operator->fresh()->password));
    }

    public function test_user_logs_in_with_the_password_reset_by_an_admin(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $operator = User::factory()->operator()->create(['username' => 'budi']);

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('aturUlangKataSandi')->table($operator), data: [
                'password' => 'kata-sandi-baru-123',
                'password_confirmation' => 'kata-sandi-baru-123',
            ])
            ->assertHasNoFormErrors();

        Filament::auth()->logout();

        Livewire::test(Login::class)
            ->fillForm(['username' => 'budi', 'password' => 'kata-sandi-baru-123'])
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($operator);
    }

    public function test_operator_does_not_see_row_actions_for_a_super_admin(): void
    {
        $operator = User::factory()->operator()->create();
        $operator->givePermissionTo(['ViewAny:User', 'Update:User']);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($operator);

        Livewire::test(ListUsers::class)
            ->assertActionHidden(TestAction::make('edit')->table($superAdmin))
            ->assertActionHidden(TestAction::make('nonaktifkan')->table($superAdmin))
            ->assertActionHidden(TestAction::make('aturUlangKataSandi')->table($superAdmin));
    }

    public function test_edit_form_shows_the_panel_role_of_a_guru_operator_and_saving_keeps_guru(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $user = User::factory()->create(['name' => 'Siti']);
        $user->assignRole([Peran::Guru->value, Peran::Operator->value]);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertSchemaStateSet(['peran' => Peran::Operator->value])
            ->fillForm(['name' => 'Siti Aminah'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(['guru', 'operator'], $user->fresh()->getRoleNames()->all());
    }

    public function test_role_field_offers_only_panel_roles_and_may_be_left_empty(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $guru = User::factory()->create();
        $guru->assignRole(Peran::Guru->value);

        Livewire::test(EditUser::class, ['record' => $guru->getRouteKey()])
            ->assertSchemaStateSet(['peran' => null])
            ->assertFormFieldExists('peran', fn (Select $field): bool => $field->getOptions() === ['super_admin' => 'Super Admin', 'operator' => 'Operator']
                && ! $field->isRequired()
                && $field->getPlaceholder() === 'Tanpa akses panel')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['guru'], $guru->fresh()->getRoleNames()->all());
    }

    public function test_super_admin_creates_a_user_without_panel_access(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Siti Aminah',
                'username' => 'siti',
                'peran' => null,
                'password' => 'rahasia-baru-123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertCount(0, User::query()->where('username', 'siti')->firstOrFail()->roles);
    }
}
