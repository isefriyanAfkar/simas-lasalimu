<?php

namespace Tests\Feature\Domains\Academic\Policies;

use App\Domains\Academic\Models\Semester;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Academic\Services\TahunAjaranService;
use App\Domains\Foundation\Models\User;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TahunAjaranPolicyTest extends TestCase
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
        $tahunAjaran = $this->schoolYear();

        $this->assertTrue($operator->can('viewAny', TahunAjaran::class));
        $this->assertTrue($operator->can('view', $tahunAjaran));
        $this->assertTrue($operator->can('create', TahunAjaran::class));
        $this->assertTrue($operator->can('update', $tahunAjaran));
        $this->assertFalse($operator->can('delete', $tahunAjaran));
    }

    public function test_operator_may_view_and_update_semesters_but_never_add_or_remove_them(): void
    {
        $operator = User::factory()->operator()->create();
        $semester = $this->schoolYear()->semesterGanjil;

        $this->assertTrue($operator->can('viewAny', Semester::class));
        $this->assertTrue($operator->can('view', $semester));
        $this->assertTrue($operator->can('update', $semester));
        $this->assertFalse($operator->can('create', Semester::class));
        $this->assertFalse($operator->can('delete', $semester));
        $this->assertFalse($operator->can('deleteAny', Semester::class));
    }

    public function test_super_admin_may_delete_an_inactive_school_year(): void
    {
        $this->assertTrue(User::factory()->superAdmin()->create()->can('delete', $this->schoolYear()));
    }

    public function test_user_without_role_is_denied_everything(): void
    {
        $user = User::factory()->create();
        $tahunAjaran = $this->schoolYear();

        $this->assertFalse($user->can('viewAny', TahunAjaran::class));
        $this->assertFalse($user->can('create', TahunAjaran::class));
        $this->assertFalse($user->can('update', $tahunAjaran));
        $this->assertFalse($user->can('viewAny', Semester::class));
    }

    private function schoolYear(): TahunAjaran
    {
        return app(TahunAjaranService::class)->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26']);
    }
}
