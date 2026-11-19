<?php

namespace Tests\Feature\Domains\People\Policies;

use App\Domains\Foundation\Models\User;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Models\WaliMurid;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WaliMuridPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
    }

    public function test_seeded_operator_may_view_update_and_delete_parents_and_manage_them_on_a_student(): void
    {
        $operator = User::factory()->operator()->create();
        $wali = WaliMurid::factory()->create();

        $this->assertTrue($operator->can('viewAny', WaliMurid::class));
        $this->assertTrue($operator->can('view', $wali));
        $this->assertTrue($operator->can('update', $wali));
        $this->assertTrue($operator->can('delete', $wali));
        $this->assertTrue($operator->can('kelolaWali', Siswa::factory()->create()));
    }

    public function test_no_one_creates_a_parent_outside_a_student(): void
    {
        $this->assertFalse(User::factory()->operator()->create()->can('create', WaliMurid::class));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function deniedRoles(): array
    {
        return [
            'tanpa role' => [null],
            'guru' => ['guru'],
            'wali' => ['wali'],
        ];
    }

    #[DataProvider('deniedRoles')]
    public function test_other_roles_are_denied_everything(?string $role): void
    {
        $user = User::factory()->create();

        if ($role !== null) {
            $user->assignRole($role);
        }

        $wali = WaliMurid::factory()->create();

        $this->assertFalse($user->can('viewAny', WaliMurid::class));
        $this->assertFalse($user->can('view', $wali));
        $this->assertFalse($user->can('update', $wali));
        $this->assertFalse($user->can('delete', $wali));
        $this->assertFalse($user->can('kelolaWali', Siswa::factory()->create()));
    }
}
