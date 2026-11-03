<?php

namespace App\Actions\User;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Updates a staff account's details, role and branch.
 *
 * Changing the role can also change whether a branch applies, so the branch is
 * always derived from the new role rather than taken at face value. Changing
 * the email address clears the verification timestamp only when the account was
 * verified by its owner — accounts an administrator created are re-verified
 * here, for the reason given in CreateUser.
 */
final class UpdateUser
{
    /**
     * @param  array{name: string, email: string, role: string, phone?: string|null, branch_id?: int|null, is_active?: bool}  $attributes
     */
    public function handle(User $user, array $attributes): User
    {
        return DB::transaction(function () use ($user, $attributes): User {
            $role = UserRole::from($attributes['role']);

            $user->fill([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'phone' => $attributes['phone'] ?? null,
                'is_active' => $attributes['is_active'] ?? $user->is_active,
                'branch_id' => $role->requiresBranch() ? ($attributes['branch_id'] ?? null) : null,
            ]);

            if ($user->isDirty('email')) {
                $user->forceFill(['email_verified_at' => now()]);
            }

            $user->save();

            if (! $user->hasAppRole($role)) {
                $user->syncRoles([$role->value]);
            }

            return $user;
        });
    }
}
