<?php

namespace Tests\Feature\Domains\People\Policies;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Models\Ptk;
use App\Domains\People\Policies\PtkPolicy;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PtkPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function staffRoles(): array
    {
        return [
            'super_admin' => ['superAdmin'],
            'operator' => ['operator'],
        ];
    }

    #[DataProvider('staffRoles')]
    public function test_super_admin_and_operator_may_view_create_and_update_a_guru(string $state): void
    {
        $user = User::factory()->{$state}()->create();
        $ptk = Ptk::factory()->create();

        $this->assertTrue($user->can('viewAny', Ptk::class));
        $this->assertTrue($user->can('view', $ptk));
        $this->assertTrue($user->can('create', Ptk::class));
        $this->assertTrue($user->can('update', $ptk));
    }

    #[DataProvider('staffRoles')]
    public function test_operator_may_view_but_not_update_the_staff_record_of_a_super_admin_or_another_operator(string $state): void
    {
        $operator = User::factory()->operator()->create();
        $ptk = Ptk::factory()->for(User::factory()->{$state}())->create();

        $this->assertTrue($operator->can('view', $ptk));
        $this->assertFalse($operator->can('update', $ptk));
    }

    public function test_operator_may_update_their_own_staff_record(): void
    {
        $operator = User::factory()->operator()->create();
        $ptk = Ptk::factory()->for($operator)->create();

        $this->assertTrue($operator->can('update', $ptk));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function deniedRoles(): array
    {
        return [
            'guru' => ['guru'],
            'tendik' => ['tendik'],
            'tanpa role' => [null],
        ];
    }

    #[DataProvider('deniedRoles')]
    public function test_every_other_role_is_denied_everything(?string $role): void
    {
        $user = User::factory()->create();

        if ($role !== null) {
            $user->assignRole($role);
        }

        $ptk = Ptk::factory()->create();

        $this->assertFalse($user->can('viewAny', Ptk::class));
        $this->assertFalse($user->can('view', $ptk));
        $this->assertFalse($user->can('create', Ptk::class));
        $this->assertFalse($user->can('update', $ptk));
    }

    public function test_the_policy_never_allows_deleting(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $policy = new PtkPolicy(app(UserService::class));

        $this->assertFalse($policy->delete($superAdmin, Ptk::factory()->create()));
        $this->assertFalse($policy->deleteAny($superAdmin));
    }

    public function test_the_operator_role_holds_every_staff_permission(): void
    {
        $operator = User::factory()->operator()->create();

        $this->assertSame(['ViewAny:Ptk', 'View:Ptk', 'Create:Ptk', 'Update:Ptk'], PeranDanIzinSeeder::PTK_PERMISSIONS);

        foreach (PeranDanIzinSeeder::PTK_PERMISSIONS as $permission) {
            $this->assertTrue($operator->hasPermissionTo($permission), $permission);
        }
    }
}
