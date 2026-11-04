<?php

use App\Enums\UserRole;
use App\Models\User;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();

    // Users are soft deleted so their past orders and stock movements keep a
    // valid actor (see docs/03-data-model.md).
    expect($user->fresh()->trashed())->toBeTrue()
        ->and(User::find($user->id))->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});

/*
 * BUG-01 (docs/bugs/2026-11-04-review-p1-e1-e2.md): a panel account may not be
 * deleted from the settings screen. The Super Admin role is the only holder of
 * `roles.manage`, so a Super Admin who deleted themselves would leave nobody
 * able to hand the role to anyone else, and the soft-deleted row would keep
 * their email address (ADR-010).
 */

it('refuses to let a panel account delete itself', function (UserRole $role) {
    $user = User::factory()->withRole($role)->forBranch()->create();

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertForbidden();

    expect($user->fresh()?->trashed())->toBeFalse();
    $this->assertAuthenticatedAs($user);
})->with([
    'super admin' => [UserRole::SuperAdmin],
    'admin pusat' => [UserRole::AdminPusat],
    'admin cabang' => [UserRole::AdminCabang],
    'staff gudang' => [UserRole::StaffGudang],
    'kasir' => [UserRole::Kasir],
]);

it('leaves the system with a Super Admin even when there is only one', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertForbidden();

    expect(User::query()->ofRole(UserRole::SuperAdmin)->active()->count())->toBe(1);
});

it('still lets a customer delete their own account', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('home'));

    expect($customer->fresh()->trashed())->toBeTrue();
    $this->assertGuest();
});

it('hides the delete card from panel accounts', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page->where('auth.isStaff', true));

    $this->actingAs(User::factory()->customer()->create())
        ->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page->where('auth.isStaff', false));
});
