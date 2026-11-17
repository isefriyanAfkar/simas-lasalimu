<?php

namespace Database\Seeders;

use App\Domains\Foundation\Enums\Peran;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates every role (the panel roles super_admin and operator, and the staff
 * roles guru and tendik, which hold no permission and never open the panel)
 * and the permissions. Safe to run repeatedly.
 * The operator role deliberately receives no User permission for now except
 * ViewNik:User, every TahunAjaran and Rombel permission except Delete, and
 * every MataPelajaran permission.
 */
class PeranDanIzinSeeder extends Seeder
{
    /**
     * Permission keys in Shield's format (pascal case, ":" separator).
     *
     * @var list<string>
     */
    public const array USER_PERMISSIONS = [
        'ViewAny:User',
        'View:User',
        'Create:User',
        'Update:User',
        'ViewNik:User',
    ];

    /**
     * @var list<string>
     */
    public const array TAHUN_AJARAN_PERMISSIONS = [
        'ViewAny:TahunAjaran',
        'View:TahunAjaran',
        'Create:TahunAjaran',
        'Update:TahunAjaran',
        'Delete:TahunAjaran',
    ];

    /**
     * No Delete: subjects are never deleted, only deactivated (Update).
     *
     * @var list<string>
     */
    public const array MATA_PELAJARAN_PERMISSIONS = [
        'ViewAny:MataPelajaran',
        'View:MataPelajaran',
        'Create:MataPelajaran',
        'Update:MataPelajaran',
    ];

    /**
     * @var list<string>
     */
    public const array ROMBEL_PERMISSIONS = [
        'ViewAny:Rombel',
        'View:Rombel',
        'Create:Rombel',
        'Update:Rombel',
        'Delete:Rombel',
    ];

    /**
     * Given to no role: the Status Kepegawaian screen is for the super_admin
     * only (Gate::before). No Delete: a status is only deactivated (Update).
     *
     * @var list<string>
     */
    public const array STATUS_KEPEGAWAIAN_PERMISSIONS = [
        'ViewAny:StatusKepegawaian',
        'View:StatusKepegawaian',
        'Create:StatusKepegawaian',
        'Update:StatusKepegawaian',
    ];

    /**
     * Permissions the operator role holds. Deleting a school year or a rombel
     * is left to the super_admin. ViewNik:User (reveal a NIK in full, logged)
     * has no screen for operators yet: it is for the future staff and student
     * screens, where operators manage biodata.
     *
     * @var list<string>
     */
    public const array OPERATOR_PERMISSIONS = [
        'ViewNik:User',
        'ViewAny:TahunAjaran',
        'View:TahunAjaran',
        'Create:TahunAjaran',
        'Update:TahunAjaran',
        ...self::MATA_PELAJARAN_PERMISSIONS,
        'ViewAny:Rombel',
        'View:Rombel',
        'Create:Rombel',
        'Update:Rombel',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Peran::cases() as $peran) {
            Role::findOrCreate($peran->value, 'web');
        }

        foreach ([...self::USER_PERMISSIONS, ...self::TAHUN_AJARAN_PERMISSIONS, ...self::MATA_PELAJARAN_PERMISSIONS, ...self::ROMBEL_PERMISSIONS, ...self::STATUS_KEPEGAWAIAN_PERMISSIONS] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findByName(Peran::Operator->value, 'web')->givePermissionTo(self::OPERATOR_PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
