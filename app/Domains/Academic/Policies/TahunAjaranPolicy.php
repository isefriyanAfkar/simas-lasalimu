<?php

namespace App\Domains\Academic\Policies;

use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Foundation\Models\User;

/**
 * Hand-written policy. TahunAjaranResource is excluded from Shield in
 * config/filament-shield.php, so `shield:generate` never overwrites this file.
 * Permissions are created by PeranDanIzinSeeder; the operator gets every one
 * except Delete. Activating follows Update.
 *
 * A super_admin passes every ability through Shield's Gate::before.
 */
class TahunAjaranPolicy
{
    public function viewAny(User $authUser): bool
    {
        return $authUser->can('ViewAny:TahunAjaran');
    }

    public function view(User $authUser, TahunAjaran $tahunAjaran): bool
    {
        return $authUser->can('View:TahunAjaran');
    }

    public function create(User $authUser): bool
    {
        return $authUser->can('Create:TahunAjaran');
    }

    /**
     * Also covers activating the school year and its semesters.
     */
    public function update(User $authUser, TahunAjaran $tahunAjaran): bool
    {
        return $authUser->can('Update:TahunAjaran');
    }

    public function delete(User $authUser, TahunAjaran $tahunAjaran): bool
    {
        return $authUser->can('Delete:TahunAjaran') && ! $tahunAjaran->status_aktif;
    }

    public function deleteAny(User $authUser): bool
    {
        return false;
    }
}
