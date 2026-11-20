<?php

namespace Tests\Feature\Domains\Foundation\Services;

use App\Domains\Foundation\Enums\Peran;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\Foundation\Services\UserService;
use Closure;
use Database\Seeders\PeranDanIzinSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Kelola Pengguna manages only the panel role (super_admin, operator, or
 * none). The guru and tendik roles belong to the staff screen and are never
 * touched by create() or update().
 */
class UserServicePeranTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
    }

    public function test_the_seeder_creates_the_staff_student_and_parent_roles(): void
    {
        $this->assertEqualsCanonicalizing(
            ['super_admin', 'operator', 'guru', 'tendik', 'siswa', 'wali'],
            Role::query()->pluck('name')->all(),
        );
    }

    /**
     * @return array<string, array{Peran}>
     */
    public static function ptkRoles(): array
    {
        return [
            'guru' => [Peran::Guru],
            'tendik' => [Peran::Tendik],
        ];
    }

    #[DataProvider('ptkRoles')]
    public function test_changing_the_panel_role_keeps_the_guru_or_tendik_role(Peran $peranPtk): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $user = User::factory()->operator()->create();
        $user->assignRole($peranPtk->value);

        $this->service()->update($superAdmin, $user, $this->updateData($user, Peran::SuperAdmin->value));

        $this->assertEqualsCanonicalizing(['super_admin', $peranPtk->value], $user->fresh()->getRoleNames()->all());
    }

    public function test_saving_without_a_role_change_keeps_the_guru_role(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $user = User::factory()->operator()->create();
        $user->assignRole(Peran::Guru->value);

        $this->service()->update($superAdmin, $user, [...$this->updateData($user, Peran::Operator->value), 'name' => 'Nama Baru']);

        $this->assertEqualsCanonicalizing(['operator', 'guru'], $user->fresh()->getRoleNames()->all());
        $this->assertSame(0, Activity::query()->where('event', 'role_berubah')->count());
    }

    public function test_a_user_may_be_created_without_panel_access(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $user = $this->service()->create($superAdmin, [
            'name' => 'Budi Santoso',
            'username' => 'budi',
            'password' => 'rahasia-baru-123',
            'peran' => null,
        ]);

        $this->assertCount(0, $user->fresh()->roles);
        $log = Activity::query()->where('event', 'dibuat')->sole();
        $this->assertArrayHasKey('peran', $log->attribute_changes['attributes']);
        $this->assertNull($log->attribute_changes['attributes']['peran']);
    }

    public function test_removing_panel_access_keeps_the_guru_role_closes_the_panel_and_is_logged(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $user = User::factory()->operator()->create();
        $user->assignRole(Peran::Guru->value);

        $this->service()->update($superAdmin, $user, $this->updateData($user, null));

        $user = $user->fresh();
        $this->assertSame(['guru'], $user->getRoleNames()->all());
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
        $log = Activity::query()->where('event', 'role_berubah')->sole();
        $this->assertSame(['peran' => null], $log->attribute_changes['attributes']);
        $this->assertSame(['peran' => 'operator'], $log->attribute_changes['old']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidPanelRoles(): array
    {
        return [
            'guru' => ['guru'],
            'tendik' => ['tendik'],
            'tidak dikenal' => ['kepala_sekolah'],
        ];
    }

    #[DataProvider('invalidPanelRoles')]
    public function test_create_and_update_accept_only_a_panel_role(mixed $peran): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $user = User::factory()->create();

        $this->assertValidationError('peran', fn () => $this->service()->create($superAdmin, [
            'name' => 'Budi Santoso',
            'username' => 'budi',
            'password' => 'rahasia-baru-123',
            'peran' => $peran,
        ]));
        $this->assertValidationError('peran', fn () => $this->service()->update($superAdmin, $user, $this->updateData($user, $peran)));

        $this->assertDatabaseMissing('users', ['username' => 'budi']);
        $this->assertCount(0, $user->fresh()->roles);
    }

    public function test_the_peran_key_must_be_sent(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $user = User::factory()->operator()->create();

        $data = $this->updateData($user, Peran::Operator->value);
        unset($data['peran']);

        $this->assertValidationError('peran', fn () => $this->service()->update($superAdmin, $user, $data));
        $this->assertTrue($user->fresh()->hasRole(Peran::Operator->value));
    }

    public function test_operator_may_not_remove_their_own_panel_role(): void
    {
        $operator = User::factory()->operator()->create();

        $this->assertRejected(
            PelanggaranAturanPengguna::tidakBolehMencabutPeran(Peran::Operator->value),
            fn () => $this->service()->update($operator, $operator, $this->updateData($operator, null)),
        );

        $this->assertTrue($operator->fresh()->hasRole(Peran::Operator->value));
    }

    public function test_the_last_active_super_admin_may_not_drop_to_no_panel_role(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertRejected(
            PelanggaranAturanPengguna::superAdminAktifTerakhir(),
            fn () => $this->service()->update($superAdmin, $superAdmin, $this->updateData($superAdmin, null)),
        );

        $this->assertTrue($superAdmin->fresh()->isSuperAdmin());
    }

    public function test_operator_may_grant_only_guru_and_tendik(): void
    {
        $operator = User::factory()->operator()->create();

        $this->assertTrue($this->service()->canGrantRole($operator, Peran::Guru));
        $this->assertTrue($this->service()->canGrantRole($operator, Peran::Tendik));
        $this->assertFalse($this->service()->canGrantRole($operator, Peran::Operator));
        $this->assertFalse($this->service()->canGrantRole($operator, Peran::SuperAdmin));
    }

    public function test_the_role_options_of_kelola_pengguna_hold_only_panel_roles(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertSame(
            ['super_admin' => 'Super Admin', 'operator' => 'Operator'],
            $this->service()->grantableRoleOptions($superAdmin),
        );
    }

    public function test_the_panel_role_of_a_user_ignores_guru_and_tendik(): void
    {
        $guruOperator = User::factory()->create();
        $guruOperator->assignRole([Peran::Guru->value, Peran::Operator->value]);
        $guru = User::factory()->create();
        $guru->assignRole(Peran::Guru->value);

        $this->assertSame(Peran::Operator, $guruOperator->fresh()->peranPanel());
        $this->assertNull($guru->fresh()->peranPanel());
    }

    public function test_operator_may_create_a_user_without_panel_access(): void
    {
        $operator = User::factory()->operator()->create();

        $user = $this->service()->create($operator, [
            'name' => 'Budi Santoso',
            'username' => 'budi',
            'password' => 'rahasia-baru-123',
            'peran' => null,
        ]);

        $this->assertCount(0, $user->fresh()->roles);
        $log = Activity::query()->where('event', 'dibuat')->sole();
        $this->assertTrue($log->causer->is($operator));
        $this->assertNull($log->attribute_changes['attributes']['peran']);
    }

    public function test_the_panel_role_of_a_user_holding_both_is_super_admin(): void
    {
        $user = User::factory()->create();
        $user->assignRole([Peran::Operator->value, Peran::SuperAdmin->value]);

        $this->assertSame(Peran::SuperAdmin, $user->fresh()->peranPanel());
    }

    public function test_removing_panel_access_from_a_user_holding_both_panel_roles_removes_both(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $user = User::factory()->create();
        $user->assignRole([Peran::Operator->value, Peran::SuperAdmin->value, Peran::Guru->value]);

        $this->service()->update($superAdmin, $user->fresh(), $this->updateData($user, null));

        $user = $user->fresh();
        $this->assertSame(['guru'], $user->getRoleNames()->all());
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
        $log = Activity::query()->where('event', 'role_berubah')->sole();
        $this->assertSame(['peran' => null], $log->attribute_changes['attributes']);
        $this->assertSame(['peran' => 'super_admin'], $log->attribute_changes['old']);
    }

    public function test_choosing_one_panel_role_for_a_user_holding_both_leaves_only_that_one(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $user = User::factory()->create();
        $user->assignRole([Peran::SuperAdmin->value, Peran::Operator->value]);

        $this->service()->update($superAdmin, $user->fresh(), $this->updateData($user, Peran::Operator->value));

        $this->assertSame(['operator'], $user->fresh()->getRoleNames()->all());
        $log = Activity::query()->where('event', 'role_berubah')->sole();
        $this->assertSame(['peran' => 'operator'], $log->attribute_changes['attributes']);
        $this->assertSame(['peran' => 'super_admin'], $log->attribute_changes['old']);
    }

    public function test_the_last_super_admin_also_holding_operator_may_save_without_a_role_change(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole([Peran::Operator->value, Peran::SuperAdmin->value]);
        $superAdmin = $superAdmin->fresh();

        $this->service()->update($superAdmin, $superAdmin, [...$this->updateData($superAdmin, Peran::SuperAdmin->value), 'name' => 'Nama Baru']);

        $this->assertSame(['super_admin'], $superAdmin->fresh()->getRoleNames()->all());
        $this->assertSame('Nama Baru', $superAdmin->fresh()->name);
    }

    /**
     * Another super_admin changed the role between this page's load and its
     * save: the role is read again after the account row is locked.
     */
    public function test_the_current_role_is_read_after_the_lock_not_from_the_loaded_relation(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $user = User::factory()->operator()->create();
        $dimuat = $user->fresh()->load('roles');

        $user->fresh()->syncRoles([Peran::SuperAdmin->value]);

        $this->service()->update($superAdmin, $dimuat, $this->updateData($user, null));

        $this->assertCount(0, $user->fresh()->roles);
        $log = Activity::query()->where('event', 'role_berubah')->sole();
        $this->assertSame(['peran' => 'super_admin'], $log->attribute_changes['old']);
    }

    #[DataProvider('ptkRoles')]
    public function test_a_guru_or_tendik_only_account_may_not_enter_the_panel(Peran $peranPtk): void
    {
        $user = User::factory()->create();
        $user->assignRole($peranPtk->value);

        $this->assertFalse($user->fresh()->canAccessPanel(Filament::getPanel('admin')));
    }

    private function service(): UserService
    {
        return app(UserService::class);
    }

    /**
     * @return array{name: string, username: string, email: string|null, peran: mixed}
     */
    private function updateData(User $user, mixed $peran): array
    {
        return [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'peran' => $peran,
        ];
    }

    private function assertRejected(PelanggaranAturanPengguna $expected, Closure $operation): void
    {
        try {
            $operation();
        } catch (PelanggaranAturanPengguna $exception) {
            $this->assertSame($expected->getMessage(), $exception->getMessage());

            return;
        }

        $this->fail('Expected the operation to be rejected.');
    }

    private function assertValidationError(string $field, Closure $operation): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors(), 'Errors: '.json_encode($exception->errors()));

            return;
        }

        $this->fail("Expected a validation error on [{$field}].");
    }
}
