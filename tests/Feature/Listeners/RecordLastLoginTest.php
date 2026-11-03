<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Features;

/*
 * `users.last_login_at` is written by App\Listeners\RecordLastLogin.
 *
 * The column had no writer at all until now: it was created with the rest of
 * the profile columns and read by /admin/users, so every account reported
 * "Belum pernah" no matter how often it signed in.
 */

it('stamps the last login when an account signs in with a password', function () {
    $user = User::factory()->create(['last_login_at' => null]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();

    expect($user->refresh()->last_login_at)->not->toBeNull();
});

it('leaves the last login untouched when the password is wrong', function () {
    $user = User::factory()->create(['last_login_at' => null]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();

    expect($user->refresh()->last_login_at)->toBeNull();
});

it('leaves the last login untouched for a deactivated account', function () {
    $user = User::factory()->inactive()->create(['last_login_at' => null]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();

    expect($user->refresh()->last_login_at)->toBeNull();
});

it('replaces the stamp on each later sign in', function () {
    $user = User::factory()->create(['last_login_at' => now()->subMonth()]);
    $before = $user->last_login_at;

    // Factories mass-assign through Model::unguarded(), so the column really
    // does hold the older value here even though it is not fillable.
    expect($before)->not->toBeNull();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    expect($user->refresh()->last_login_at->greaterThan($before))->toBeTrue();
});

it('does not stamp the password step of a two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create(['last_login_at' => null]);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    // Credentials verified but no session yet, so nothing is recorded. This is
    // why the listener hooks the Login event instead of authenticateUsing().
    $this->assertGuest();

    expect($user->refresh()->last_login_at)->toBeNull();
});

it('stamps any sign in that reaches the guard, not just the password form', function () {
    $user = User::factory()->create(['last_login_at' => null]);

    // Passkeys and the two factor challenge both end in Auth::login(), which is
    // what fires the event the listener subscribes to.
    Auth::login($user);

    expect($user->refresh()->last_login_at)->not->toBeNull();
});

it('keeps the sign in out of the audit log', function () {
    $user = User::factory()->create(['last_login_at' => null]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    expect(AuditLog::where('auditable_type', $user->getMorphClass())
        ->where('auditable_id', $user->id)
        ->where('event', AuditLog::EVENT_UPDATED)
        ->count())->toBe(0);
});
