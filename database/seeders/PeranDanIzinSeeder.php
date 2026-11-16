<?php

namespace Database\Seeders;

use App\Domains\Foundation\Enums\Peran;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the panel roles and their permissions. Safe to run repeatedly.
 * The operator role deliberately receives no User permission for now, every
 * TahunAjaran permission except Delete, and every MataPelajaran permission.
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
     * Permissions the operator role holds. Deleting a school year is left to
     * the super_admin.
     *
     * @var list<string>
     */
    public const array OPERATOR_PERMISSIONS = [
        'ViewAny:TahunAjaran',
        'View:TahunAjaran',
        'Create:TahunAjaran',
        'Update:TahunAjaran',
        ...self::MATA_PELAJARAN_PERMISSIONS,
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Peran::cases() as $peran) {
            Role::findOrCreate($peran->value, 'web');
        }

        foreach ([...self::USER_PERMISSIONS, ...self::TAHUN_AJARAN_PERMISSIONS, ...self::MATA_PELAJARAN_PERMISSIONS] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findByName(Peran::Operator->value, 'web')->givePermissionTo(self::OPERATOR_PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
