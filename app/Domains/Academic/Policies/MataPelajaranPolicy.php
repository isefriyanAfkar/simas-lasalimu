<?php

namespace App\Domains\Academic\Policies;

use App\Domains\Academic\Models\MataPelajaran;
use App\Domains\Foundation\Models\User;

/**
 * Hand-written policy. MataPelajaranResource is excluded from Shield in
 * config/filament-shield.php, so `shield:generate` never overwrites this file.
 * Permissions are created by PeranDanIzinSeeder; there is no Delete
 * permission because subjects are never deleted, only deactivated.
 * Deactivating and activating follow Update.
 *
 * A super_admin passes every ability through Shield's Gate::before.
 */
class MataPelajaranPolicy
{
    public function viewAny(User $authUser): bool
    {
        return $authUser->can('ViewAny:MataPelajaran');
    }

    public function view(User $authUser, MataPelajaran $mataPelajaran): bool
    {
        return $authUser->can('View:MataPelajaran');
    }

    public function create(User $authUser): bool
    {
        return $authUser->can('Create:MataPelajaran');
    }

    /**
     * Also covers deactivating and activating.
     */
    public function update(User $authUser, MataPelajaran $mataPelajaran): bool
    {
        return $authUser->can('Update:MataPelajaran');
    }

    public function delete(User $authUser, MataPelajaran $mataPelajaran): bool
    {
        return false;
    }

    public function deleteAny(User $authUser): bool
    {
        return false;
    }
}
