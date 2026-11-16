<?php

namespace Tests\Feature\Domains\Academic\Policies;

use App\Domains\Academic\Models\MataPelajaran;
use App\Domains\Academic\Policies\MataPelajaranPolicy;
use App\Domains\Foundation\Models\User;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MataPelajaranPolicyTest extends TestCase
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
    public static function roles(): array
    {
        return [
            'super_admin' => ['superAdmin'],
            'operator' => ['operator'],
        ];
    }

    #[DataProvider('roles')]
    public function test_super_admin_and_operator_may_view_create_and_update(string $state): void
    {
        $user = User::factory()->{$state}()->create();
        $mataPelajaran = MataPelajaran::factory()->create();

        $this->assertTrue($user->can('viewAny', MataPelajaran::class));
        $this->assertTrue($user->can('view', $mataPelajaran));
        $this->assertTrue($user->can('create', MataPelajaran::class));
        $this->assertTrue($user->can('update', $mataPelajaran));
    }

    public function test_operator_may_not_delete(): void
    {
        $operator = User::factory()->operator()->create();
        $mataPelajaran = MataPelajaran::factory()->create();

        $this->assertFalse($operator->can('delete', $mataPelajaran));
        $this->assertFalse($operator->can('deleteAny', MataPelajaran::class));
    }

    /**
     * A super_admin passes every ability through Shield's Gate::before, so the
     * policy itself is checked here; deleting is also impossible because the
     * service has no delete and the screen offers no delete action.
     */
    public function test_the_policy_never_allows_deleting(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $policy = new MataPelajaranPolicy;

        $this->assertFalse($policy->delete($superAdmin, MataPelajaran::factory()->create()));
        $this->assertFalse($policy->deleteAny($superAdmin));
    }

    public function test_user_without_role_is_denied_everything(): void
    {
        $user = User::factory()->create();
        $mataPelajaran = MataPelajaran::factory()->create();

        $this->assertFalse($user->can('viewAny', MataPelajaran::class));
        $this->assertFalse($user->can('view', $mataPelajaran));
        $this->assertFalse($user->can('create', MataPelajaran::class));
        $this->assertFalse($user->can('update', $mataPelajaran));
    }
}
