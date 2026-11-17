<?php

namespace App\Domains\People\Policies;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Models\Ptk;

/**
 * Hand-written policy. PtkResource is excluded from Shield in
 * config/filament-shield.php, so `shield:generate` never overwrites this
 * file. PeranDanIzinSeeder creates the permissions and gives all of them to
 * the operator. There is no Delete permission: staff are never deleted.
 *
 * Updating (which also covers changing the status and reactivating) needs
 * the same rule as managing the account in UserService::canManage: an
 * operator may view, but not change, the staff record of a super_admin or of
 * another operator. A super_admin passes every ability through Shield's
 * Gate::before.
 */
class PtkPolicy
{
    public function __construct(private UserService $userService) {}

    public function viewAny(User $authUser): bool
    {
        return $authUser->can('ViewAny:Ptk');
    }

    public function view(User $authUser, Ptk $ptk): bool
    {
        return $authUser->can('View:Ptk');
    }

    public function create(User $authUser): bool
    {
        return $authUser->can('Create:Ptk');
    }

    /**
     * Also covers changing the status and reactivating.
     */
    public function update(User $authUser, Ptk $ptk): bool
    {
        return $authUser->can('Update:Ptk') && $this->userService->canManage($authUser, $ptk->user);
    }

    public function delete(User $authUser, Ptk $ptk): bool
    {
        return false;
    }

    public function deleteAny(User $authUser): bool
    {
        return false;
    }
}
