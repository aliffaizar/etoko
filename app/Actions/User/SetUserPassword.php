<?php

namespace App\Actions\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Replaces a staff account's password on an administrator's behalf.
 *
 * Unlike the self-service password change this does not ask for the current
 * password, so it is guarded by `UserPolicy::resetPassword`. Every other
 * session of that account is invalidated, because a reset is the response to a
 * lost or shared password.
 */
final class SetUserPassword
{
    public function handle(User $user, string $password): User
    {
        return DB::transaction(function () use ($user, $password): User {
            $user->password = $password;
            $user->setRememberToken(Str::random(60));
            $user->save();

            return $user;
        });
    }
}
