<?php

namespace App\Domains\Foundation\Policies;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Models\Ptk;

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
     * Reveal a user's NIK in full ("Tampilkan NIK"): the ViewNik:User
     * permission, and only for a user the actor may manage (the same rule as
     * update), so an operator never sees a super_admin's or another
     * operator's NIK. Every use is logged by UserService::tampilkanNik().
     */
    public function viewNik(User $authUser, User $user): bool
    {
        return $authUser->can('ViewNik:User') && $this->userService->canManage($authUser, $user);
    }

    /**
     * Look a user up by NIK ("Cari NIK"). A match the actor may not reveal
     * is reported as no match by UserService::cariNik().
     */
    public function searchNik(User $authUser): bool
    {
        return $authUser->can('ViewNik:User');
    }

    /**
     * See the user's photo (pengguna.foto route): always one's own, otherwise
     * whoever may view that user, or that user's staff or student record. An
     * account without a staff or student record may also be seen by whoever
     * may link it on the Guru dan Staf form: allowed to create staff and to
     * manage the account (operators cannot view users, but the form shows the
     * photo).
     */
    public function viewFoto(User $authUser, User $user): bool
    {
        if ($authUser->is($user) || $this->view($authUser, $user)) {
            return true;
        }

        $ptk = $user->ptk;

        if ($ptk !== null) {
            return $authUser->can('view', $ptk);
        }

        $siswa = $user->siswa;

        if ($siswa !== null) {
            return $authUser->can('view', $siswa);
        }

        return $authUser->can('create', Ptk::class) && $this->userService->canManage($authUser, $user);
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
