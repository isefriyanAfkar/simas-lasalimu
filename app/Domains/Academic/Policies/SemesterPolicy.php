<?php

namespace App\Domains\Academic\Policies;

use App\Domains\Academic\Models\Semester;
use App\Domains\Foundation\Models\User;

/**
 * Semesters follow the TahunAjaran permissions. They are never added or
 * removed by hand, so create and delete are always denied (a super_admin
 * still passes Gate::before, but no screen offers those actions).
 */
class SemesterPolicy
{
    public function viewAny(User $authUser): bool
    {
        return $authUser->can('ViewAny:TahunAjaran');
    }

    public function view(User $authUser, Semester $semester): bool
    {
        return $authUser->can('View:TahunAjaran');
    }

    /**
     * Changing dates and activating.
     */
    public function update(User $authUser, Semester $semester): bool
    {
        return $authUser->can('Update:TahunAjaran');
    }

    public function create(User $authUser): bool
    {
        return false;
    }

    public function delete(User $authUser, Semester $semester): bool
    {
        return false;
    }

    public function deleteAny(User $authUser): bool
    {
        return false;
    }
}
