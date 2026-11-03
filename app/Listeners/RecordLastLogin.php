<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Stamps `users.last_login_at` whenever an account signs in.
 *
 * This listens to `Login` rather than hooking into `Fortify::authenticateUsing`
 * because the event is the one point every route to a session passes through:
 * the password form, the two-factor challenge, a passkey, and the remember-me
 * cookie. Writing it from the credential check instead would stamp an account
 * with two-factor enabled even when it never cleared the challenge.
 *
 * The write is quiet on purpose, so a login does not land in `audit_logs`.
 * `audit_logs` exists for changes to business data (BR-9); the column written
 * here is the record of the sign-in itself, and letting every login through
 * would bury the entries that matter.
 */
class RecordLastLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();
    }
}
