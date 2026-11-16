<?php

namespace App\Domains\Academic\Policies;

use App\Domains\Academic\Models\Rombel;
use App\Domains\Foundation\Models\User;

/**
 * Hand-written policy. RombelResource is excluded from Shield in
 * config/filament-shield.php, so `shield:generate` never overwrites this file.
 * Permissions are created by PeranDanIzinSeeder; the operator gets every one
 * except Delete.
 *
 * A super_admin passes every ability through Shield's Gate::before.
 */
class RombelPolicy
{
    public function viewAny(User $authUser): bool
    {
        return $authUser->can('ViewAny:Rombel');
    }

    public function view(User $authUser, Rombel $rombel): bool
    {
        return $authUser->can('View:Rombel');
    }

    public function create(User $authUser): bool
    {
        return $authUser->can('Create:Rombel');
    }

    public function update(User $authUser, Rombel $rombel): bool
    {
        return $authUser->can('Update:Rombel');
    }

    public function delete(User $authUser, Rombel $rombel): bool
    {
        return $authUser->can('Delete:Rombel');
    }

    public function deleteAny(User $authUser): bool
    {
        return false;
    }
}
