<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Installs the role and permission baseline from config/rbac.php.
 *
 * Safe to re-run: it creates what is missing and re-syncs each role's
 * permissions, so adding a permission to the config is a matter of seeding again.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var array<string, array<int, string>> $groups */
        $groups = config('rbac.permissions');
        $permissionNames = Arr::flatten($groups);

        foreach ($permissionNames as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // The registrar caches the permission collection on first read, so it has
        // to be dropped again before any role syncs against the new rows.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Super Admin holds every permission; it additionally bypasses the gate
        // through the Gate::before check registered in AppServiceProvider.
        Role::findOrCreate(UserRole::SuperAdmin->value, 'web')->syncPermissions($permissionNames);

        /** @var array<string, array<int, string>> $rolePermissions */
        $rolePermissions = config('rbac.role_permissions');

        foreach ($rolePermissions as $roleName => $permissions) {
            Role::findOrCreate($roleName, 'web')->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
