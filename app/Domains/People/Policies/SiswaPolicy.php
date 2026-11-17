<?php

namespace App\Domains\People\Policies;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Models\Siswa;

/**
 * Hand-written policy. SiswaResource is excluded from Shield in
 * config/filament-shield.php, so `shield:generate` never overwrites this
 * file. PeranDanIzinSeeder creates the permissions and gives all of them to
 * the operator. There is no Delete permission: students are never deleted.
 *
 * Updating (which also covers changing the status and reactivating) needs
 * the same rule as managing the account in UserService::canManage. A
 * super_admin passes every ability through Shield's Gate::before.
 */
class SiswaPolicy
{
    public function __construct(private UserService $userService) {}

    public function viewAny(User $authUser): bool
    {
        return $authUser->can('ViewAny:Siswa');
    }

    public function view(User $authUser, Siswa $siswa): bool
    {
        return $authUser->can('View:Siswa');
    }

    public function create(User $authUser): bool
    {
        return $authUser->can('Create:Siswa');
    }

    /**
     * Also covers changing the status and reactivating.
     */
    public function update(User $authUser, Siswa $siswa): bool
    {
        return $authUser->can('Update:Siswa') && $this->userService->canManage($authUser, $siswa->user);
    }

    public function delete(User $authUser, Siswa $siswa): bool
    {
        return false;
    }

    public function deleteAny(User $authUser): bool
    {
        return false;
    }
}
