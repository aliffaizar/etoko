<?php

use App\Enums\UserRole;
use App\Http\Responses\PasskeyLoginResponse;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

test('central staff land on the central dashboard', function (UserRole $role) {
    $user = User::factory()->withRole($role)->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('admin.dashboard'));
})->with([
    'super admin' => [UserRole::SuperAdmin],
    'admin pusat' => [UserRole::AdminPusat],
]);

test('branch staff land on the branch dashboard', function (UserRole $role) {
    $user = User::factory()->withRole($role)->forBranch()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('branch.dashboard'));
})->with([
    'admin cabang' => [UserRole::AdminCabang],
    'staff gudang' => [UserRole::StaffGudang],
]);

test('a cashier falls back to the branch dashboard until the POS exists', function () {
    $user = User::factory()->kasir()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('branch.dashboard'));
});

test('customers land on the storefront', function () {
    $user = User::factory()->customer()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('home'));
});

test('a user without a role lands on the default home', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));
});

test('a cashier who followed a central link still lands on their own panel', function () {
    $user = User::factory()->kasir()->create();

    $this->get(route('admin.users.index'))->assertRedirect(route('login'));

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('branch.dashboard'));
});

test('a customer who followed a panel link still lands on the storefront', function () {
    $user = User::factory()->customer()->create();

    $this->get(route('branch.dashboard'))->assertRedirect(route('login'));

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('home'));
});

test('a user bounced off a page they may open continues there after signing in', function () {
    $user = User::factory()->adminPusat()->create();

    $this->get(route('admin.users.index'))->assertRedirect(route('login'));

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('admin.users.index'));
});

test('a role lands on its own panel after the two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $user = User::factory()->adminPusat()->withTwoFactor()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $response = $this->post(route('two-factor.login'), [
        'recovery_code' => 'recovery-code-1',
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('admin.dashboard'));
});

test('a passkey sign-in reports the role panel to the browser', function () {
    $this->skipUnlessFortifyHas(Features::passkeys());

    $user = User::factory()->adminCabang()->create();

    $request = Request::create(route('passkey.login'), 'POST');
    $request->headers->set('Accept', 'application/json');
    $request->setLaravelSession($this->app['session.store']);
    $request->setUserResolver(fn (): User => $user);

    $response = app(PasskeyLoginResponseContract::class)->toResponse($request);

    expect(app(PasskeyLoginResponseContract::class))->toBeInstanceOf(PasskeyLoginResponse::class)
        ->and($response->getData(true))->toBe(['redirect' => route('branch.dashboard')]);
});

test('verifying an email lands the role on its own panel', function () {
    $this->skipUnlessFortifyHas(Features::emailVerification());

    $user = User::factory()->adminPusat()->unverified()->create();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    $response->assertRedirect(route('admin.dashboard').'?verified=1');
});

test('a new registration lands on the storefront', function () {
    $this->skipUnlessFortifyHas(Features::registration());

    $response = $this->post(route('register.store'), [
        'name' => 'Budi Santoso',
        'email' => 'budi@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect(route('home'));
});

test('a role holder is forwarded from the generic dashboard to their panel', function (UserRole $role, string $routeName) {
    $user = User::factory()
        ->withRole($role)
        ->when($role->requiresBranch(), fn (UserFactory $factory): UserFactory => $factory->forBranch())
        ->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertRedirect(route($routeName));
})->with([
    'super admin' => [UserRole::SuperAdmin, 'admin.dashboard'],
    'admin cabang' => [UserRole::AdminCabang, 'branch.dashboard'],
    'kasir' => [UserRole::Kasir, 'branch.dashboard'],
    'customer' => [UserRole::Customer, 'home'],
]);

test('a deactivated user cannot authenticate', function () {
    $user = User::factory()->adminPusat()->inactive()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors('email');
});

test('a deactivated account cannot sign in with a passkey', function () {
    $this->skipUnlessFortifyHas(Features::passkeys());

    $user = User::factory()->adminPusat()->inactive()->create();

    expect(Passkeys::allowsLogin(Request::create(route('passkey.login'), 'POST'), passkeyOwnedBy($user)))
        ->toBeFalse();
});

test('an active account may sign in with a passkey', function () {
    $this->skipUnlessFortifyHas(Features::passkeys());

    $user = User::factory()->adminPusat()->create();

    expect(Passkeys::allowsLogin(Request::create(route('passkey.login'), 'POST'), passkeyOwnedBy($user)))
        ->toBeTrue();
});

test('a passkey left behind by a deleted account cannot sign in', function () {
    $this->skipUnlessFortifyHas(Features::passkeys());

    expect(Passkeys::allowsLogin(Request::create(route('passkey.login'), 'POST'), passkeyOwnedBy(null)))
        ->toBeFalse();
});

test('a deactivated user cannot be told their account is deactivated', function () {
    $user = User::factory()->inactive()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasErrorsIn('default', ['email' => trans('auth.failed')]);
});

test('new registrations receive the customer role', function () {
    $this->post(route('register.store'), [
        'name' => 'Siti Rahma',
        'email' => 'siti@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'siti@example.com')->sole();

    expect($user->appRole())->toBe(UserRole::Customer);
});

/**
 * A passkey bound to the given owner, without touching the passkeys table.
 *
 * `Passkeys::allowsLogin()` only ever reads the owner off the passkey, and the
 * owner is null once the account behind it has been soft deleted.
 */
function passkeyOwnedBy(?User $user): Passkey
{
    $passkey = new Passkey;
    $passkey->setRelation('user', $user);

    return $passkey;
}
