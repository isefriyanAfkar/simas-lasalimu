<?php

namespace App\Domains\Foundation\Services;

use App\Domains\Foundation\Enums\Peran;
use App\Domains\Foundation\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only place that writes user accounts. Every method takes the acting
 * user ($pelaku) so the user management rules can be enforced here.
 *
 * Rules:
 * - a user cannot deactivate their own account
 * - the last active super_admin cannot be deactivated or lose the super_admin role
 * - a non super_admin cannot change, (de)activate or reset the password of a
 *   super_admin or of another operator
 * - a non super_admin cannot grant the super_admin or operator role
 */
class UserService
{
    /**
     * @param  array{name: string, username: string, email?: string|null, password: string, peran: string}  $data
     */
    public function create(User $pelaku, array $data): User
    {
        $peran = Peran::from($data['peran']);

        $this->ensureCanGrantRole($pelaku, $peran);

        return DB::transaction(function () use ($data, $peran): User {
            $user = User::create([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?? null,
                'password' => $data['password'],
                'status_aktif' => true,
            ]);

            $user->syncRoles([$peran->value]);

            return $user;
        });
    }

    /**
     * @param  array{name: string, username: string, email?: string|null, peran: string}  $data
     */
    public function update(User $pelaku, User $user, array $data): User
    {
        $this->ensureCanManage($pelaku, $user);

        $peran = Peran::from($data['peran']);

        if (! $user->hasRole($peran->value)) {
            $this->ensureCanGrantRole($pelaku, $peran);
        }

        return DB::transaction(function () use ($user, $data, $peran): User {
            if ($peran !== Peran::SuperAdmin) {
                $this->ensureNotLastActiveSuperAdmin($user);
            }

            $user->update([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?? null,
            ]);

            $user->syncRoles([$peran->value]);

            return $user;
        });
    }

    public function activate(User $pelaku, User $user): User
    {
        $this->ensureCanManage($pelaku, $user);

        $user->update(['status_aktif' => true]);

        return $user;
    }

    public function deactivate(User $pelaku, User $user): User
    {
        $this->ensureCanManage($pelaku, $user);

        return DB::transaction(function () use ($pelaku, $user): User {
            $this->ensureNotLastActiveSuperAdmin($user);

            if ($pelaku->is($user)) {
                throw PelanggaranAturanPengguna::menonaktifkanDiriSendiri();
            }

            $user->update(['status_aktif' => false]);

            return $user;
        });
    }

    public function resetPassword(User $pelaku, User $user, string $kataSandiBaru): User
    {
        $this->ensureCanManage($pelaku, $user);

        $user->update(['password' => $kataSandiBaru]);

        return $user;
    }

    /**
     * Whether $pelaku may change $user at all. Also used by UserPolicy so the
     * UI hides what the service would reject.
     */
    public function canManage(User $pelaku, User $user): bool
    {
        if ($pelaku->isSuperAdmin()) {
            return true;
        }

        if ($user->isSuperAdmin()) {
            return false;
        }

        return ! ($user->hasRole(Peran::Operator->value) && ! $pelaku->is($user));
    }

    public function canGrantRole(User $pelaku, Peran $peran): bool
    {
        return $pelaku->isSuperAdmin();
    }

    /**
     * @return array<string, string>
     */
    public function grantableRoleOptions(User $pelaku): array
    {
        return array_filter(
            Peran::options(),
            fn (string $value): bool => $this->canGrantRole($pelaku, Peran::from($value)),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function ensureCanManage(User $pelaku, User $user): void
    {
        if (! $this->canManage($pelaku, $user)) {
            throw PelanggaranAturanPengguna::tidakBolehMengelola();
        }
    }

    private function ensureCanGrantRole(User $pelaku, Peran $peran): void
    {
        if (! $this->canGrantRole($pelaku, $peran)) {
            throw PelanggaranAturanPengguna::tidakBolehMemberiPeran($peran->value);
        }
    }

    /**
     * Must run inside a transaction: the other active super admins are locked
     * so two concurrent requests cannot both remove the last one.
     */
    private function ensureNotLastActiveSuperAdmin(User $user): void
    {
        if (! $user->status_aktif || ! $user->isSuperAdmin()) {
            return;
        }

        $otherActiveSuperAdminIds = User::query()
            ->role(Peran::SuperAdmin->value)
            ->where('status_aktif', true)
            ->whereKeyNot($user->getKey())
            ->lockForUpdate()
            ->pluck('id');

        if ($otherActiveSuperAdminIds->isEmpty()) {
            throw PelanggaranAturanPengguna::superAdminAktifTerakhir();
        }
    }
}
