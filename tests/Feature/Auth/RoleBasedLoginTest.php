<?php

use App\Enums\UserRole;
use App\Models\User;

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

test('a deactivated user cannot authenticate', function () {
    $user = User::factory()->adminPusat()->inactive()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors('email');
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
