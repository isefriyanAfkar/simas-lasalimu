<?php

namespace App\Domains\Foundation\Policies;

use App\Domains\Foundation\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * Hand-written policy for the activity log. Reading is locked to the
 * super_admin role on purpose (not to a permission), and writing is never
 * allowed. ActivityLogResource is excluded from Shield, so `shield:generate`
 * never overwrites this file. Registered in AppServiceProvider.
 */
class ActivityPolicy
{
    public function viewAny(User $authUser): bool
    {
        return $authUser->isSuperAdmin();
    }

    public function view(User $authUser, Activity $activity): bool
    {
        return $authUser->isSuperAdmin();
    }

    public function create(User $authUser): bool
    {
        return false;
    }

    public function update(User $authUser, Activity $activity): bool
    {
        return false;
    }

    public function delete(User $authUser, Activity $activity): bool
    {
        return false;
    }

    public function deleteAny(User $authUser): bool
    {
        return false;
    }
}
