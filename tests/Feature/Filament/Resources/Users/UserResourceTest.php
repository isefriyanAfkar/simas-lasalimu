<?php

namespace Tests\Feature\Filament\Resources\Users;

use App\Domains\Foundation\Enums\Peran;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use Database\Seeders\PeranDanIzinSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
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
}
