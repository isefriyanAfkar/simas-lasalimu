<?php

namespace Tests\Feature\Domains\Foundation\Services;

use App\Domains\Foundation\Enums\Peran;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\Foundation\Services\UserService;
use Closure;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
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
