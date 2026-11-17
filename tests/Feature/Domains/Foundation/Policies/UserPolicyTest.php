<?php

namespace Tests\Feature\Domains\Foundation\Policies;

use App\Domains\Foundation\Models\User;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
    }

    public function test_seeded_operator_is_denied_every_user_ability(): void
    {
        $operator = User::factory()->operator()->create();
        $userWithoutRole = User::factory()->create();

        $this->assertFalse($operator->can('viewAny', User::class));
        $this->assertFalse($operator->can('view', $userWithoutRole));
        $this->assertFalse($operator->can('create', User::class));
        $this->assertFalse($operator->can('update', $userWithoutRole));
    }

    public function test_update_permission_lets_operator_update_only_users_it_may_manage(): void
    {
        $operator = User::factory()->operator()->create();
        $operator->givePermissionTo('Update:User');

        $this->assertTrue($operator->can('update', User::factory()->create()));
        $this->assertTrue($operator->can('update', $operator));
        $this->assertFalse($operator->can('update', User::factory()->superAdmin()->create()));
        $this->assertFalse($operator->can('update', User::factory()->operator()->create()));
    }

    public function test_operator_cannot_delete_even_with_every_user_permission(): void
    {
        $operator = User::factory()->operator()->create();
        $operator->givePermissionTo(PeranDanIzinSeeder::USER_PERMISSIONS);

        $this->assertFalse($operator->can('delete', User::factory()->create()));
        $this->assertFalse($operator->can('deleteAny', User::class));
    }

    public function test_view_nik_is_allowed_to_super_admin_and_seeded_operator_only(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(User::factory()->superAdmin()->create()->can('viewNik', $user));
        $this->assertTrue(User::factory()->operator()->create()->can('viewNik', $user));
        $this->assertFalse(User::factory()->create()->can('viewNik', $user));
    }

    public function test_view_nik_follows_the_same_rule_as_managing_the_user(): void
    {
        $operator = User::factory()->operator()->create();

        $this->assertTrue($operator->can('viewNik', $operator));
        $this->assertFalse($operator->can('viewNik', User::factory()->superAdmin()->create()));
        $this->assertFalse($operator->can('viewNik', User::factory()->operator()->create()));
        $this->assertTrue(User::factory()->superAdmin()->create()->can('viewNik', User::factory()->superAdmin()->create()));
    }

    public function test_search_nik_needs_only_the_view_nik_permission(): void
    {
        $this->assertTrue(User::factory()->operator()->create()->can('searchNik', User::class));
        $this->assertTrue(User::factory()->superAdmin()->create()->can('searchNik', User::class));
        $this->assertFalse(User::factory()->create()->can('searchNik', User::class));
    }

    public function test_view_foto_allows_the_owner_and_whoever_may_view_the_user(): void
    {
        $user = User::factory()->create();
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('View:User');

        $this->assertTrue($user->can('viewFoto', $user));
        $this->assertTrue($viewer->can('viewFoto', $user));
        $this->assertTrue(User::factory()->superAdmin()->create()->can('viewFoto', $user));
        $this->assertFalse(User::factory()->operator()->create()->can('viewFoto', $user));
        $this->assertFalse(User::factory()->create()->can('viewFoto', $user));
    }

    public function test_view_nik_follows_the_view_nik_permission(): void
    {
        $user = User::factory()->create();
        $withPermission = User::factory()->create();
        $withPermission->givePermissionTo('ViewNik:User');

        $this->assertTrue($withPermission->can('viewNik', $user));
        $this->assertTrue($withPermission->can('viewNik', $withPermission));
    }

    public function test_super_admin_may_view_and_update_any_user(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $otherSuperAdmin = User::factory()->superAdmin()->create();

        $this->assertTrue($superAdmin->can('viewAny', User::class));
        $this->assertTrue($superAdmin->can('create', User::class));
        $this->assertTrue($superAdmin->can('update', $otherSuperAdmin));
    }
}
