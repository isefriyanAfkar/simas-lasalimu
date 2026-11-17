<?php

namespace Tests\Feature\Domains\People\Policies;

use App\Domains\Foundation\Models\User;
use App\Domains\People\Models\StatusKepegawaian;
use App\Domains\People\Policies\StatusKepegawaianPolicy;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StatusKepegawaianPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
    }

    public function test_super_admin_may_view_create_and_update(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $status = StatusKepegawaian::factory()->create();

        $this->assertTrue($superAdmin->can('viewAny', StatusKepegawaian::class));
        $this->assertTrue($superAdmin->can('view', $status));
        $this->assertTrue($superAdmin->can('create', StatusKepegawaian::class));
        $this->assertTrue($superAdmin->can('update', $status));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function deniedRoles(): array
    {
        return [
            'operator' => ['operator'],
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

        $status = StatusKepegawaian::factory()->create();

        $this->assertFalse($user->can('viewAny', StatusKepegawaian::class));
        $this->assertFalse($user->can('view', $status));
        $this->assertFalse($user->can('create', StatusKepegawaian::class));
        $this->assertFalse($user->can('update', $status));
    }

    /**
     * A super_admin passes every ability through Shield's Gate::before, so the
     * policy itself is checked here.
     */
    public function test_the_policy_never_allows_deleting(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $policy = new StatusKepegawaianPolicy;

        $this->assertFalse($policy->delete($superAdmin, StatusKepegawaian::factory()->create()));
        $this->assertFalse($policy->deleteAny($superAdmin));
    }

    public function test_the_permissions_exist_but_the_operator_role_holds_none(): void
    {
        $operator = User::factory()->operator()->create();

        foreach (PeranDanIzinSeeder::STATUS_KEPEGAWAIAN_PERMISSIONS as $permission) {
            $this->assertFalse($operator->hasPermissionTo($permission), $permission);
        }

        $this->assertSame(
            ['ViewAny:StatusKepegawaian', 'View:StatusKepegawaian', 'Create:StatusKepegawaian', 'Update:StatusKepegawaian'],
            PeranDanIzinSeeder::STATUS_KEPEGAWAIAN_PERMISSIONS,
        );
    }
}
