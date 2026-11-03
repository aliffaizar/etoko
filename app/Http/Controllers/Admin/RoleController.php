<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Rbac\SyncRolePermissions;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateRolePermissionsRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Role & Permission management (P1-E1-T5).
 *
 * Roles and permissions live in the database so they can be changed without a
 * deploy, while `config/rbac.php` stays the catalogue of what exists and the
 * baseline the seeder installs (ADR-009). This screen therefore edits the
 * assignment, never the catalogue.
 *
 * Only a Super Admin holds `roles.manage`, which the routes enforce. The Super
 * Admin role itself is read-only here: it is granted every permission by the
 * seeder and additionally bypasses the gate (`Gate::before`), so editing its
 * list would suggest a limit that does not exist.
 */
class RoleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/roles/index', [
            'roles' => $this->roles(),
            'permissionTotal' => count($this->catalogue()),
        ]);
    }

    public function edit(Role $role): Response
    {
        $this->denyProtectedRole($role);

        return Inertia::render('admin/roles/edit', [
            'role' => [
                'id' => (int) $role->id,
                'name' => $role->name,
                'label' => $this->labelFor($role->name),
                'permissions' => $role->permissions->pluck('name')->values()->all(),
            ],
            'groups' => $this->permissionGroups(),
        ]);
    }

    public function update(UpdateRolePermissionsRequest $request, Role $role, SyncRolePermissions $syncRolePermissions): RedirectResponse
    {
        $this->denyProtectedRole($role);

        $syncRolePermissions->handle($role, $request->permissions());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Permission untuk {$this->labelFor($role->name)} berhasil disimpan.",
        ]);

        return to_route('admin.roles.edit', $role);
    }

    /**
     * Roles cannot be edited through this screen if changing them would be
     * misleading or would lock everyone out.
     */
    private function denyProtectedRole(Role $role): void
    {
        abort_if(
            $role->name === UserRole::SuperAdmin->value,
            403,
            'Role Super Admin selalu memiliki seluruh permission dan tidak dapat diubah.',
        );
    }

    /**
     * @return array<int, array{id: int, name: string, label: string, is_editable: bool, permission_count: int, user_count: int, is_central: bool, requires_branch: bool}>
     */
    private function roles(): array
    {
        return Role::query()
            ->withCount(['permissions', 'users'])
            ->orderBy('name')
            ->get()
            // Ordered by the role hierarchy rather than alphabetically, so
            // Super Admin reads first and Customer last. There are only a
            // handful of roles, so sorting in PHP keeps the query plain.
            ->sortBy(fn (Role $role): int => $this->hierarchyPosition($role->name))
            ->values()
            ->map(function (Role $role): array {
                $appRole = UserRole::tryFrom($role->name);

                return [
                    // `roles.id` is a bigIncrements column in this application
                    // (see create_permission_tables), so the key is an integer
                    // even though Spatie's model allows a string key.
                    'id' => (int) $role->id,
                    'name' => $role->name,
                    'label' => $this->labelFor($role->name),
                    'is_editable' => $role->name !== UserRole::SuperAdmin->value,
                    'permission_count' => (int) $role->permissions_count,
                    'user_count' => (int) $role->users_count,
                    'is_central' => $appRole?->isCentral() ?? false,
                    'requires_branch' => $appRole?->requiresBranch() ?? false,
                ];
            })
            ->all();
    }

    /**
     * Position of a role name in `UserRole`, or last for a role that is not one
     * of the application's own.
     */
    private function hierarchyPosition(string $roleName): int
    {
        $values = array_map(fn (UserRole $role): string => $role->value, UserRole::cases());
        $position = array_search($roleName, $values, strict: true);

        return $position === false ? count($values) : $position;
    }

    /**
     * The permission catalogue grouped for the checkbox sections of the form.
     *
     * @return array<int, array{key: string, label: string, permissions: array<int, array{name: string, action: string}>}>
     */
    private function permissionGroups(): array
    {
        /** @var array<string, array<int, string>> $groups */
        $groups = config('rbac.permissions');

        /** @var array<string, string> $labels */
        $labels = config('rbac.permission_group_labels', []);

        return array_map(fn (string $key) => [
            'key' => $key,
            'label' => $labels[$key] ?? $key,
            'permissions' => array_map(fn (string $name): array => [
                'name' => $name,
                'action' => $this->actionLabel($name),
            ], $groups[$key]),
        ], array_keys($groups));
    }

    /**
     * The part of a `domain.action` permission that distinguishes it, humanised.
     */
    private function actionLabel(string $permission): string
    {
        $segments = explode('.', $permission);
        array_shift($segments);

        return str_replace('_', ' ', implode(' · ', $segments));
    }

    /**
     * @return array<int, string>
     */
    private function catalogue(): array
    {
        /** @var array<string, array<int, string>> $groups */
        $groups = config('rbac.permissions');

        return array_merge(...array_values($groups));
    }

    private function labelFor(string $roleName): string
    {
        return UserRole::tryFrom($roleName)?->label() ?? $roleName;
    }
}
