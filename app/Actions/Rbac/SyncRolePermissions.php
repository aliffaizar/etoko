<?php

namespace App\Actions\Rbac;

use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Replaces a role's permissions with the given set.
 *
 * Only permissions from the catalogue in `config/rbac.php` are accepted, which
 * the form request enforces, so a renamed or retired permission cannot be
 * resurrected from a stale form. The permission cache is dropped afterwards or
 * the change would not be visible until the cache expired (ADR-009).
 */
final class SyncRolePermissions
{
    /**
     * @param  array<int, string>  $permissions
     */
    public function handle(Role $role, array $permissions): Role
    {
        return DB::transaction(function () use ($role, $permissions): Role {
            $role->syncPermissions($permissions);

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $role->refresh();
        });
    }
}
