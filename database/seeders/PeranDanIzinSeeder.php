<?php

namespace Database\Seeders;

use App\Domains\Foundation\Enums\Peran;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the panel roles and the User permissions. Safe to run repeatedly.
 * The operator role deliberately receives no User permission for now.
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

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Peran::cases() as $peran) {
            Role::findOrCreate($peran->value, 'web');
        }

        foreach (self::USER_PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
