<?php

namespace App\Domains\Foundation\Policies;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\UserService;

/**
 * Hand-written policy. UserResource is excluded from Shield in
 * config/filament-shield.php, so `shield:generate` never overwrites this file.
 *
 * A super_admin passes every ability through Shield's Gate::before, so these
 * methods only decide for other roles.
 */
class UserPolicy
{
    public function __construct(private UserService $userService) {}

    public function viewAny(User $authUser): bool
    {
        return $authUser->can('ViewAny:User');
    }

    public function view(User $authUser, User $user): bool
    {
        return $authUser->can('View:User');
    }

    public function create(User $authUser): bool
    {
        return $authUser->can('Create:User');
    }

    /**
     * Also covers activating, deactivating and resetting the password.
     */
    public function update(User $authUser, User $user): bool
    {
        return $authUser->can('Update:User') && $this->userService->canManage($authUser, $user);
    }

    /**
     * Reveal a user's NIK in full ("Tampilkan NIK"). Every use is logged by
     * UserService::tampilkanNik().
     */
    public function viewNik(User $authUser, User $user): bool
    {
        return $authUser->can('ViewNik:User');
    }

    public function delete(User $authUser, User $user): bool
    {
        return false;
    }

    public function deleteAny(User $authUser): bool
    {
        return false;
    }
}
