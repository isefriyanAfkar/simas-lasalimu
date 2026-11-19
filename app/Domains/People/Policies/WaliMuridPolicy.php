<?php

namespace App\Domains\People\Policies;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Models\WaliMurid;

/**
 * Hand-written policy (the Wali Murid screen comes in Langkah 8B2 and is
 * excluded from Shield). PeranDanIzinSeeder creates the permissions and
 * gives all of them to the operator. A parent is never created on its own,
 * only from a student (SiswaPolicy::kelolaWali), so there is no Create. Delete
 * covers a parent without children; WaliMuridService refuses the others.
 *
 * A super_admin passes every ability through Shield's Gate::before.
 */
class WaliMuridPolicy
{
    public function __construct(private UserService $userService) {}

    public function viewAny(User $authUser): bool
    {
        return $authUser->can('ViewAny:WaliMurid');
    }

    public function view(User $authUser, WaliMurid $waliMurid): bool
    {
        return $authUser->can('View:WaliMurid');
    }

    public function create(User $authUser): bool
    {
        return false;
    }

    /**
     * Also covers marking as died.
     */
    public function update(User $authUser, WaliMurid $waliMurid): bool
    {
        return $authUser->can('Update:WaliMurid') && $this->userService->canManage($authUser, $waliMurid->user);
    }

    public function delete(User $authUser, WaliMurid $waliMurid): bool
    {
        return $authUser->can('Delete:WaliMurid') && $this->userService->canManage($authUser, $waliMurid->user);
    }

    public function deleteAny(User $authUser): bool
    {
        return false;
    }
}
