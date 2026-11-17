<?php

namespace App\Domains\People\Policies;

use App\Domains\Foundation\Models\User;
use App\Domains\People\Models\StatusKepegawaian;

/**
 * Hand-written policy. StatusKepegawaianResource is excluded from Shield in
 * config/filament-shield.php, so `shield:generate` never overwrites this
 * file. PeranDanIzinSeeder creates the permissions but gives them to no
 * role: the screen is for the super_admin only, who passes every ability
 * through Shield's Gate::before. There is no Delete permission because a
 * status is never deleted; deactivating and activating follow Update.
 */
class StatusKepegawaianPolicy
{
    public function viewAny(User $authUser): bool
    {
        return $authUser->can('ViewAny:StatusKepegawaian');
    }

    public function view(User $authUser, StatusKepegawaian $statusKepegawaian): bool
    {
        return $authUser->can('View:StatusKepegawaian');
    }

    public function create(User $authUser): bool
    {
        return $authUser->can('Create:StatusKepegawaian');
    }

    /**
     * Also covers deactivating and activating.
     */
    public function update(User $authUser, StatusKepegawaian $statusKepegawaian): bool
    {
        return $authUser->can('Update:StatusKepegawaian');
    }

    public function delete(User $authUser, StatusKepegawaian $statusKepegawaian): bool
    {
        return false;
    }

    public function deleteAny(User $authUser): bool
    {
        return false;
    }
}
