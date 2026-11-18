<?php

use App\Models\User;
use App\Support\AdminHost;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

/*
 * The staff panels on a host of their own (ADR-063, P2-E9-T7).
 *
 * Route domains are fixed when the routes are registered, so the panel host is
 * put in the environment before the application of each test boots, and taken
 * out again so the rest of the suite keeps running on one host. APP_URL is left
 * alone: Dotenv rewrites a variable it loaded itself on every boot, so the
 * storefront host is read from it instead.
 */

$previousAdminDomain = null;

beforeAll(function () use (&$previousAdminDomain) {
    $previousAdminDomain = getenv('ADMIN_DOMAIN');
    putenv('ADMIN_DOMAIN=admin.etoko.test');
    $_ENV['ADMIN_DOMAIN'] = $_SERVER['ADMIN_DOMAIN'] = 'admin.etoko.test';
});

afterAll(function () use (&$previousAdminDomain) {
    $value = $previousAdminDomain === false ? '' : (string) $previousAdminDomain;
    putenv("ADMIN_DOMAIN={$value}");
    $_ENV['ADMIN_DOMAIN'] = $_SERVER['ADMIN_DOMAIN'] = $value;
});

function onAdminHost(string $path): string
{
    return 'http://admin.etoko.test'.$path;
}

/**
 * Named in full: a relative path in a test follows the host of the request
 * before it, which would quietly send a second request to the panel host.
 */
function onStorefront(string $path): string
{
    return rtrim((string) config('app.url'), '/').$path;
}

test('the panels are not served on the storefront host', function (string $path) {
    $user = User::factory()->adminPusat()->create();

    $response = $this->actingAs($user)->get(onStorefront($path));

    $response->assertNotFound();
})->with(['central panel' => '/admin', 'branch panel' => '/branch']);

test('the panels are served on the panel host', function () {
    $user = User::factory()->adminCabang()->create();

    $response = $this->actingAs($user)->get(onAdminHost('/branch'));

    $response->assertOk();
});

test('the storefront is not served on the panel host', function () {
    $response = $this->get(onAdminHost('/produk'));

    $response->assertNotFound();
});

test('the root of the panel host forwards to the dashboard', function () {
    $response = $this->get(onAdminHost('/'));

    $response->assertRedirect(onAdminHost('/dashboard'));
});

test('a customer cannot sign in on the panel host', function () {
    $user = User::factory()->customer()->create();

    $response = $this->post(onAdminHost('/login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors(['email' => trans('auth.failed')]);
    $this->assertGuest();
});

test('staff signing in on the panel host land on their panel', function () {
    $user = User::factory()->adminPusat()->create();

    $response = $this->post(onAdminHost('/login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(onAdminHost('/admin'));
    $this->assertAuthenticatedAs($user);
});

test('staff signing in on the storefront stay on the storefront', function () {
    $user = User::factory()->adminPusat()->create();

    $response = $this->post(onStorefront('/login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(onStorefront(''));
    $this->assertAuthenticatedAs($user);
});

test('a customer cannot sign in with a passkey on the panel host', function () {
    $this->skipUnlessFortifyHas(Features::passkeys());

    $passkey = new Passkey;
    $passkey->setRelation('user', User::factory()->customer()->create());

    expect(Passkeys::allowsLogin(Request::create(onAdminHost('/passkey/login'), 'POST'), $passkey))->toBeFalse()
        ->and(Passkeys::allowsLogin(Request::create(onStorefront('/passkey/login'), 'POST'), $passkey))->toBeTrue();
});

test('staff may sign in with a passkey on the panel host', function () {
    $this->skipUnlessFortifyHas(Features::passkeys());

    $passkey = new Passkey;
    $passkey->setRelation('user', User::factory()->adminPusat()->create());

    expect(Passkeys::allowsLogin(Request::create(onAdminHost('/passkey/login'), 'POST'), $passkey))->toBeTrue();
});

test('sign-up is refused on the panel host', function () {
    $this->get(onAdminHost('/register'))->assertNotFound();

    $response = $this->post(onAdminHost('/register'), [
        'name' => 'Siti Rahma',
        'email' => 'siti@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertNotFound();
    $this->assertDatabaseMissing('users', ['email' => 'siti@example.com']);
});

test('the sign-in screen offers sign-up only on the storefront', function () {
    $this->get(onAdminHost('/login'))
        ->assertInertia(fn (Assert $page) => $page->where('canRegister', false));

    $this->get(onStorefront('/login'))
        ->assertInertia(fn (Assert $page) => $page->where('canRegister', true));
});

test('a staff password reset link leads to the panel host', function () {
    Notification::fake();
    $user = User::factory()->adminPusat()->create();

    $this->post(onStorefront('/forgot-password'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $link = $notification->toMail($user)->actionUrl;

        return parse_url($link, PHP_URL_HOST) === 'admin.etoko.test'
            && str_starts_with((string) parse_url($link, PHP_URL_PATH), '/reset-password/');
    });
});

test('a customer password reset link leads to the storefront even when asked on the panel host', function () {
    Notification::fake();
    $user = User::factory()->customer()->create();

    $this->post(onAdminHost('/forgot-password'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        return str_starts_with($notification->toMail($user)->actionUrl, onStorefront('/reset-password/'));
    });
});

test('staff settings use the shop shell on the storefront', function () {
    $user = User::factory()->adminPusat()->create();

    $this->actingAs($user)->get(onStorefront('/settings/profile'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('panelsReachable', false)
            ->where('notificationBell', null));

    $this->actingAs($user)->get(onAdminHost('/settings/profile'))
        ->assertInertia(fn (Assert $page) => $page->where('panelsReachable', true));
});

test('pages carry the root of each host, port included, for links that cross hosts', function () {
    $port = parse_url(onStorefront(''), PHP_URL_PORT);

    $response = $this->get(onStorefront('/login'));

    $response->assertInertia(fn (Assert $page) => $page->where('hostRoots', [
        'storefront' => onStorefront(''),
        'panel' => 'http://admin.etoko.test'.($port !== null ? ":{$port}" : ''),
    ]));
});

test('the route helpers are generated without hosts, so their paths keep working on any port', function () {
    $previousArgv = $_SERVER['argv'];
    $_SERVER['argv'] = ['artisan', 'wayfinder:generate'];

    try {
        expect(AdminHost::panelRouteDomain())->toBeNull()
            ->and(AdminHost::storefrontRouteDomain())->toBeNull();
    } finally {
        $_SERVER['argv'] = $previousArgv;
    }

    expect(AdminHost::panelRouteDomain())->toBe('admin.etoko.test');
});

test('a guest on the panel host is sent from an error page to sign in', function () {
    $response = $this->get(onAdminHost('/tidak-ada'));

    $response->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('error')
            ->where('homeUrl', onAdminHost('/login')));
});
