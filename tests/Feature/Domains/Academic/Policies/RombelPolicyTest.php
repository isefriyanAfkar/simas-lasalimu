<?php

namespace Tests\Feature\Domains\Academic\Policies;

use App\Domains\Academic\Models\Rombel;
use App\Domains\Foundation\Models\User;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RombelPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
    }

    public function test_operator_may_view_create_and_update_but_not_delete(): void
    {
        $operator = User::factory()->operator()->create();
        $rombel = Rombel::factory()->create();

        $this->assertTrue($operator->can('viewAny', Rombel::class));
        $this->assertTrue($operator->can('view', $rombel));
        $this->assertTrue($operator->can('create', Rombel::class));
        $this->assertTrue($operator->can('update', $rombel));
        $this->assertFalse($operator->can('delete', $rombel));
        $this->assertFalse($operator->can('deleteAny', Rombel::class));
    }

    public function test_super_admin_may_delete(): void
    {
        $this->assertTrue(User::factory()->superAdmin()->create()->can('delete', Rombel::factory()->create()));
    }

    public function test_user_without_role_is_denied_everything(): void
    {
        $user = User::factory()->create();
        $rombel = Rombel::factory()->create();

        $this->assertFalse($user->can('viewAny', Rombel::class));
        $this->assertFalse($user->can('view', $rombel));
        $this->assertFalse($user->can('create', Rombel::class));
        $this->assertFalse($user->can('update', $rombel));
        $this->assertFalse($user->can('delete', $rombel));
    }
}
