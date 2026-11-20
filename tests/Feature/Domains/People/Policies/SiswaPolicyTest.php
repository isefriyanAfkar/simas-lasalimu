<?php

namespace Tests\Feature\Domains\People\Policies;

use App\Domains\Foundation\Models\User;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Policies\SiswaPolicy;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SiswaPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
    }

    public function test_seeded_operator_may_view_create_and_update_students(): void
    {
        $operator = User::factory()->operator()->create();
        $siswa = Siswa::factory()->create();

        $this->assertTrue($operator->can('viewAny', Siswa::class));
        $this->assertTrue($operator->can('view', $siswa));
        $this->assertTrue($operator->can('create', Siswa::class));
        $this->assertTrue($operator->can('update', $siswa));
        $this->assertTrue($operator->can('import', Siswa::class));
    }

    public function test_a_user_without_role_is_denied_everything(): void
    {
        $user = User::factory()->create();
        $siswa = Siswa::factory()->create();

        $this->assertFalse($user->can('viewAny', Siswa::class));
        $this->assertFalse($user->can('view', $siswa));
        $this->assertFalse($user->can('create', Siswa::class));
        $this->assertFalse($user->can('update', $siswa));
        $this->assertFalse($user->can('import', Siswa::class));
    }

    public function test_a_student_or_teacher_role_grants_nothing(): void
    {
        $siswa = Siswa::factory()->create();

        $this->assertFalse($siswa->user->can('viewAny', Siswa::class));
        $this->assertFalse($siswa->user->can('update', $siswa));
        $this->assertFalse($siswa->user->can('import', Siswa::class));
    }

    /**
     * The super_admin passes every ability through Shield's Gate::before, so
     * the policy itself is asked directly; no screen offers a delete action.
     */
    public function test_nobody_may_delete_a_student(): void
    {
        $siswa = Siswa::factory()->create();
        $operator = User::factory()->operator()->create();
        $policy = app(SiswaPolicy::class);

        $this->assertFalse($operator->can('delete', $siswa));
        $this->assertFalse($operator->can('deleteAny', Siswa::class));
        $this->assertFalse($policy->delete(User::factory()->superAdmin()->create(), $siswa));
        $this->assertFalse($policy->deleteAny(User::factory()->superAdmin()->create()));
    }

    public function test_super_admin_may_do_everything_else(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $siswa = Siswa::factory()->create();

        $this->assertTrue($superAdmin->can('viewAny', Siswa::class));
        $this->assertTrue($superAdmin->can('create', Siswa::class));
        $this->assertTrue($superAdmin->can('update', $siswa));
    }

    public function test_the_student_role_never_opens_the_admin_panel(): void
    {
        $siswa = Siswa::factory()->create();

        $this->assertSame(['siswa'], $siswa->user->getRoleNames()->all());
        $this->assertSame($siswa->nis, $siswa->user->username);
        $this->assertFalse($siswa->user->canAccessPanel(filament()->getPanel('admin')));
    }
}
