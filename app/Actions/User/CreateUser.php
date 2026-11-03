<?php

namespace App\Actions\User;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a staff account with exactly one application role.
 *
 * The role decides whether a branch is stored at all: central roles never carry
 * a `branch_id`, branch roles always do (docs/04-rbac.md). The account is
 * created pre-verified because an administrator vouched for the address; a
 * staff member would otherwise be locked out of the panels, which all sit
 * behind the `verified` middleware.
 */
final class CreateUser
{
    /**
     * @param  array{name: string, email: string, role: string, password: string, phone?: string|null, branch_id?: int|null, is_active?: bool}  $attributes
     */
    public function handle(array $attributes): User
    {
        return DB::transaction(function () use ($attributes): User {
            $role = UserRole::from($attributes['role']);

            $user = new User;

            $user->fill([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'phone' => $attributes['phone'] ?? null,
                'password' => $attributes['password'],
                'is_active' => $attributes['is_active'] ?? true,
                'branch_id' => $role->requiresBranch() ? ($attributes['branch_id'] ?? null) : null,
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

            $user->syncRoles([$role->value]);

            return $user;
        });
    }
}
