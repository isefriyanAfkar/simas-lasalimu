<?php

namespace Tests\Feature\Domains\Foundation\Services;

use App\Domains\Foundation\Enums\Peran;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\Foundation\Services\UserService;
use Closure;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class UserServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
    }

    public function test_super_admin_creates_active_user_with_the_chosen_role(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $user = $this->service()->create($superAdmin, [
            'name' => 'Budi Santoso',
            'username' => 'Budi',
            'email' => null,
            'password' => 'rahasia-baru-123',
            'peran' => Peran::Operator->value,
        ]);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'username' => 'budi', 'status_aktif' => true]);
        $this->assertTrue($user->fresh()->hasRole(Peran::Operator->value));
    }

    public function test_super_admin_resets_another_users_password(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $operator = User::factory()->operator()->create();

        $this->service()->resetPassword($superAdmin, $operator, 'kata-sandi-baru-123');

        $this->assertTrue(Hash::check('kata-sandi-baru-123', $operator->fresh()->password));
    }

    public function test_rejects_user_deactivating_themselves(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        User::factory()->superAdmin()->create();

        $this->assertRejected(
            PelanggaranAturanPengguna::menonaktifkanDiriSendiri(),
            fn () => $this->service()->deactivate($superAdmin, $superAdmin),
        );

        $this->assertTrue($superAdmin->fresh()->status_aktif);
    }

    public function test_rejects_deactivating_the_last_active_super_admin(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertRejected(
            PelanggaranAturanPengguna::superAdminAktifTerakhir(),
            fn () => $this->service()->deactivate($superAdmin, $superAdmin),
        );

        $this->assertTrue($superAdmin->fresh()->status_aktif);
    }

    public function test_rejects_removing_super_admin_role_from_the_last_active_super_admin(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertRejected(
            PelanggaranAturanPengguna::superAdminAktifTerakhir(),
            fn () => $this->service()->update($superAdmin, $superAdmin, $this->updateData($superAdmin, Peran::Operator)),
        );

        $this->assertTrue($superAdmin->fresh()->hasRole(Peran::SuperAdmin->value));
    }

    public function test_inactive_super_admins_do_not_count_as_a_remaining_super_admin(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        User::factory()->superAdmin()->nonaktif()->create();

        $this->assertRejected(
            PelanggaranAturanPengguna::superAdminAktifTerakhir(),
            fn () => $this->service()->update($superAdmin, $superAdmin, $this->updateData($superAdmin, Peran::Operator)),
        );
    }

    public function test_deactivates_a_super_admin_while_another_active_super_admin_remains(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $otherSuperAdmin = User::factory()->superAdmin()->create();

        $this->service()->deactivate($superAdmin, $otherSuperAdmin);

        $this->assertFalse($otherSuperAdmin->fresh()->status_aktif);
    }

    public function test_removes_super_admin_role_while_another_active_super_admin_remains(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $otherSuperAdmin = User::factory()->superAdmin()->create();

        $this->service()->update($superAdmin, $otherSuperAdmin, $this->updateData($otherSuperAdmin, Peran::Operator));

        $otherSuperAdmin = $otherSuperAdmin->fresh();
        $this->assertFalse($otherSuperAdmin->hasRole(Peran::SuperAdmin->value));
        $this->assertTrue($otherSuperAdmin->hasRole(Peran::Operator->value));
    }

    #[DataProvider('managementOperations')]
    public function test_rejects_operator_managing_a_super_admin(string $operation): void
    {
        $operator = User::factory()->operator()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $before = $this->snapshot($superAdmin);

        $this->assertRejected(
            PelanggaranAturanPengguna::tidakBolehMengelola(),
            fn () => $this->perform($operation, $operator, $superAdmin),
        );

        $this->assertSame($before, $this->snapshot($superAdmin));
    }

    #[DataProvider('managementOperations')]
    public function test_rejects_operator_managing_another_operator(string $operation): void
    {
        $operator = User::factory()->operator()->create();
        $otherOperator = User::factory()->operator()->create();
        $before = $this->snapshot($otherOperator);

        $this->assertRejected(
            PelanggaranAturanPengguna::tidakBolehMengelola(),
            fn () => $this->perform($operation, $operator, $otherOperator),
        );

        $this->assertSame($before, $this->snapshot($otherOperator));
    }

    #[DataProvider('roles')]
    public function test_rejects_operator_granting_a_role_when_creating_a_user(Peran $peran): void
    {
        $operator = User::factory()->operator()->create();

        $this->assertRejected(
            PelanggaranAturanPengguna::tidakBolehMemberiPeran($peran->value),
            fn () => $this->service()->create($operator, [
                'name' => 'Budi Santoso',
                'username' => 'budi',
                'password' => 'rahasia-baru-123',
                'peran' => $peran->value,
            ]),
        );

        $this->assertDatabaseMissing('users', ['username' => 'budi']);
    }

    #[DataProvider('roles')]
    public function test_rejects_operator_granting_a_role_when_updating_a_user(Peran $peran): void
    {
        $operator = User::factory()->operator()->create();
        $userWithoutRole = User::factory()->create();

        $this->assertRejected(
            PelanggaranAturanPengguna::tidakBolehMemberiPeran($peran->value),
            fn () => $this->service()->update($operator, $userWithoutRole, $this->updateData($userWithoutRole, $peran)),
        );

        $this->assertCount(0, $userWithoutRole->fresh()->roles);
    }

    public function test_logs_user_creation_with_actor_and_new_values(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $user = $this->service()->create($superAdmin, [
            'name' => 'Budi Santoso',
            'username' => 'budi',
            'email' => 'budi@sekolah.test',
            'password' => 'rahasia-baru-123',
            'peran' => Peran::Operator->value,
        ]);

        $activity = $this->latestActivity('dibuat', $superAdmin, $user);
        $this->assertSameKeysAndValues([
            'name' => 'Budi Santoso',
            'username' => 'budi',
            'email' => 'budi@sekolah.test',
            'status_aktif' => true,
            'peran' => 'operator',
        ], $activity->attribute_changes['attributes']);
    }

    public function test_logs_update_with_only_the_changed_fields(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $operator = User::factory()->operator()->create(['name' => 'Nama Lama', 'username' => 'tetap']);

        $this->service()->update($superAdmin, $operator, [
            'name' => 'Nama Baru',
            'username' => 'tetap',
            'email' => $operator->email,
            'peran' => Peran::Operator->value,
        ]);

        $activity = $this->latestActivity('diubah', $superAdmin, $operator);
        $this->assertSame(['name' => 'Nama Baru'], $activity->attribute_changes['attributes']);
        $this->assertSame(['name' => 'Nama Lama'], $activity->attribute_changes['old']);
        $this->assertSame(0, Activity::query()->where('event', 'role_berubah')->count());
    }

    public function test_does_not_log_an_update_that_changes_nothing(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $operator = User::factory()->operator()->create();

        $this->service()->update($superAdmin, $operator, $this->updateData($operator, Peran::Operator));

        $this->assertSame(0, Activity::query()->whereIn('event', ['diubah', 'role_berubah'])->count());
    }

    public function test_logs_role_change_as_its_own_entry(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $operator = User::factory()->operator()->create();

        $this->service()->update($superAdmin, $operator, $this->updateData($operator, Peran::SuperAdmin));

        $activity = $this->latestActivity('role_berubah', $superAdmin, $operator);
        $this->assertSame(['peran' => 'super_admin'], $activity->attribute_changes['attributes']);
        $this->assertSame(['peran' => 'operator'], $activity->attribute_changes['old']);
        $this->assertSame(0, Activity::query()->where('event', 'diubah')->count());
    }

    public function test_logs_activation(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $operator = User::factory()->operator()->nonaktif()->create();

        $this->service()->activate($superAdmin, $operator);

        $activity = $this->latestActivity('diaktifkan', $superAdmin, $operator);
        $this->assertSame(['status_aktif' => true], $activity->attribute_changes['attributes']);
        $this->assertSame(['status_aktif' => false], $activity->attribute_changes['old']);
    }

    public function test_logs_deactivation(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $operator = User::factory()->operator()->create();

        $this->service()->deactivate($superAdmin, $operator);

        $activity = $this->latestActivity('dinonaktifkan', $superAdmin, $operator);
        $this->assertSame(['status_aktif' => false], $activity->attribute_changes['attributes']);
        $this->assertSame(['status_aktif' => true], $activity->attribute_changes['old']);
    }

    public function test_logs_password_reset_without_any_changes(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $operator = User::factory()->operator()->create();

        $this->service()->resetPassword($superAdmin, $operator, 'kata-sandi-baru-123');

        $activity = $this->latestActivity('kata_sandi_diatur_ulang', $superAdmin, $operator);
        $this->assertCount(0, $activity->attribute_changes ?? []);
    }

    public function test_rejected_operation_leaves_no_log(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertRejected(
            PelanggaranAturanPengguna::superAdminAktifTerakhir(),
            fn () => $this->service()->deactivate($superAdmin, $superAdmin),
        );

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_user_changes_own_name_and_it_is_logged(): void
    {
        $operator = User::factory()->operator()->create(['name' => 'Nama Lama']);

        $this->service()->updateOwnProfile($operator, ['name' => 'Nama Baru', 'password' => null]);

        $this->assertSame('Nama Baru', $operator->fresh()->name);
        $activity = $this->latestActivity('profil_diubah', $operator, $operator);
        $this->assertSame(['name' => 'Nama Baru'], $activity->attribute_changes['attributes']);
        $this->assertSame(['name' => 'Nama Lama'], $activity->attribute_changes['old']);
    }

    public function test_user_changes_own_password_and_it_is_logged(): void
    {
        $operator = User::factory()->operator()->create();

        $this->service()->updateOwnProfile($operator, ['name' => $operator->name, 'password' => 'kata-sandi-baru-123']);

        $this->assertTrue(Hash::check('kata-sandi-baru-123', $operator->fresh()->password));
        $this->latestActivity('kata_sandi_diubah_sendiri', $operator, $operator);
        $this->assertSame(0, Activity::query()->where('event', 'profil_diubah')->count());
    }

    public function test_own_profile_update_ignores_username(): void
    {
        $operator = User::factory()->operator()->create(['username' => 'asli']);

        $this->service()->updateOwnProfile($operator, ['name' => $operator->name, 'username' => 'palsu', 'password' => null]);

        $this->assertSame('asli', $operator->fresh()->username);
    }

    public function test_no_log_entry_contains_a_password_or_remember_token(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $user = $this->service()->create($superAdmin, [
            'name' => 'Budi Santoso',
            'username' => 'budi',
            'password' => 'sandi-awal-12345',
            'peran' => Peran::Operator->value,
        ]);

        $this->service()->update($superAdmin, $user, ['name' => 'Budi S', 'username' => 'budi', 'email' => null, 'peran' => Peran::SuperAdmin->value]);
        $this->service()->resetPassword($superAdmin, $user, 'sandi-reset-12345');
        $this->service()->deactivate($superAdmin, $user);
        $this->service()->activate($superAdmin, $user);
        $this->service()->updateOwnProfile($user->fresh(), ['name' => 'Budi Santoso', 'password' => 'sandi-sendiri-12345']);

        $user = $user->fresh();
        $rows = DB::table('activity_log')->get();
        $this->assertCount(8, $rows);

        $forbiddenStrings = array_filter([
            'sandi-awal-12345',
            'sandi-reset-12345',
            'sandi-sendiri-12345',
            $user->getAuthPassword(),
            $user->getRememberToken(),
            '"password"',
            'remember_token',
        ], fn (?string $value): bool => filled($value));

        foreach ($rows as $row) {
            $raw = json_encode($row);

            foreach ($forbiddenStrings as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $raw);
            }
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function managementOperations(): array
    {
        return [
            'ubah' => ['update'],
            'aktifkan' => ['activate'],
            'nonaktifkan' => ['deactivate'],
            'atur ulang kata sandi' => ['resetPassword'],
        ];
    }

    /**
     * @return array<string, array{Peran}>
     */
    public static function roles(): array
    {
        return [
            'super_admin' => [Peran::SuperAdmin],
            'operator' => [Peran::Operator],
        ];
    }

    private function service(): UserService
    {
        return app(UserService::class);
    }

    private function perform(string $operation, User $pelaku, User $user): void
    {
        match ($operation) {
            'update' => $this->service()->update($pelaku, $user, [
                'name' => 'Nama Baru',
                'username' => 'nama.baru',
                'email' => null,
                'peran' => $user->roles->first()->name,
            ]),
            'activate' => $this->service()->activate($pelaku, $user),
            'deactivate' => $this->service()->deactivate($pelaku, $user),
            'resetPassword' => $this->service()->resetPassword($pelaku, $user, 'kata-sandi-baru-123'),
        };
    }

    /**
     * @return array{name: string, username: string, email: string|null, peran: string}
     */
    private function updateData(User $user, Peran $peran): array
    {
        return [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'peran' => $peran->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(User $user): array
    {
        $user = $user->fresh();

        return [
            ...$user->only(['name', 'username', 'status_aktif']),
            'password' => $user->getAuthPassword(),
            'roles' => $user->getRoleNames()->all(),
        ];
    }

    /**
     * Strict comparison that ignores key order (MySQL JSON reorders keys).
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function assertSameKeysAndValues(array $expected, array $actual): void
    {
        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual);
    }

    private function latestActivity(string $event, User $pelaku, User $subjek): Activity
    {
        $activity = Activity::query()->where('event', $event)->latest('id')->first();

        $this->assertNotNull($activity, "No activity logged for event [{$event}].");
        $this->assertSame('pengguna', $activity->log_name);
        $this->assertTrue($activity->causer->is($pelaku), 'Wrong causer.');
        $this->assertTrue($activity->subject->is($subjek), 'Wrong subject.');

        return $activity;
    }

    private function assertRejected(PelanggaranAturanPengguna $expected, Closure $operation): void
    {
        try {
            $operation();
        } catch (PelanggaranAturanPengguna $exception) {
            $this->assertSame($expected->getMessage(), $exception->getMessage());

            return;
        }

        $this->fail('Expected the rule violation: '.$expected->getMessage());
    }
}
