<?php

use App\Models\Address;
use App\Models\User;

/*
 * Customer address book (P1-E6-T3).
 *
 * Addresses are owned by the user. The default-promotion logic ensures only
 * one address per user has is_default = true at any time.
 */

function addressPayload(array $overrides = []): array
{
    return [
        'label' => 'Rumah',
        'recipient_name' => 'Budi Santoso',
        'phone' => '08123456789',
        'province_name' => 'DKI Jakarta',
        'city_name' => 'Jakarta Selatan',
        'district_name' => 'Kebayoran Baru',
        'postal_code' => '12110',
        'address_line' => 'Jl. Sudirman No. 1',
        'is_default' => false,
        ...$overrides,
    ];
}

// ── Index ──────────────────────────────────────────────────────────────────────

it('redirects guests to the login page', function () {
    $this->get(route('shop.account.addresses.index'))->assertRedirect(route('login'));
});

it('renders the address page for the authenticated user', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->get(route('shop.account.addresses.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('shop/addresses'));
});

it('shows only the current user\'s addresses', function () {
    $customer = User::factory()->customer()->create();
    $other = User::factory()->customer()->create();
    Address::factory()->forUser($customer)->count(2)->create();
    Address::factory()->forUser($other)->count(3)->create();

    $this->actingAs($customer)
        ->get(route('shop.account.addresses.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('addresses', 2));
});

// ── Store ──────────────────────────────────────────────────────────────────────

it('creates an address for the authenticated user', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->post(route('shop.account.addresses.store'), addressPayload())
        ->assertRedirect(route('shop.account.addresses.index'));

    expect($customer->addresses()->count())->toBe(1);
});

it('automatically marks first address as default', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->post(route('shop.account.addresses.store'), addressPayload(['is_default' => false]));

    expect($customer->addresses()->first()->is_default)->toBeTrue();
});

it('demotes the previous default when a new default is added', function () {
    $customer = User::factory()->customer()->create();
    $old = Address::factory()->forUser($customer)->asDefault()->create();

    $this->actingAs($customer)
        ->post(route('shop.account.addresses.store'), addressPayload(['is_default' => true]));

    expect($old->fresh()->is_default)->toBeFalse()
        ->and($customer->addresses()->where('is_default', true)->count())->toBe(1);
});

it('requires recipient_name', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->post(route('shop.account.addresses.store'), addressPayload(['recipient_name' => '']))
        ->assertSessionHasErrors('recipient_name');
});

it('refuses a guest creating an address', function () {
    $this->post(route('shop.account.addresses.store'), addressPayload())
        ->assertRedirect(route('login'));
});

// ── Update ─────────────────────────────────────────────────────────────────────

it('updates the user\'s own address', function () {
    $customer = User::factory()->customer()->create();
    $address = Address::factory()->forUser($customer)->create();

    $this->actingAs($customer)
        ->patch(route('shop.account.addresses.update', $address), addressPayload(['label' => 'Kantor']))
        ->assertRedirect(route('shop.account.addresses.index'));

    expect($address->fresh()->label)->toBe('Kantor');
});

it('returns 403 when updating another user\'s address', function () {
    $customer = User::factory()->customer()->create();
    $other = User::factory()->customer()->create();
    $address = Address::factory()->forUser($other)->create();

    $this->actingAs($customer)
        ->patch(route('shop.account.addresses.update', $address), addressPayload())
        ->assertForbidden();
});

it('promotes other address to default when the current default is unmarked', function () {
    $customer = User::factory()->customer()->create();
    $default = Address::factory()->forUser($customer)->asDefault()->create();
    $secondary = Address::factory()->forUser($customer)->create();

    $this->actingAs($customer)
        ->patch(route('shop.account.addresses.update', $default), addressPayload(['is_default' => false]));

    expect($secondary->fresh()->is_default)->toBeTrue();
});

// ── Destroy ────────────────────────────────────────────────────────────────────

it('deletes the user\'s own address', function () {
    $customer = User::factory()->customer()->create();
    $address = Address::factory()->forUser($customer)->create();

    $this->actingAs($customer)
        ->delete(route('shop.account.addresses.destroy', $address))
        ->assertRedirect(route('shop.account.addresses.index'));

    expect(Address::find($address->getKey()))->toBeNull();
});

it('returns 403 when deleting another user\'s address', function () {
    $customer = User::factory()->customer()->create();
    $other = User::factory()->customer()->create();
    $address = Address::factory()->forUser($other)->create();

    $this->actingAs($customer)
        ->delete(route('shop.account.addresses.destroy', $address))
        ->assertForbidden();
});

it('promotes another address to default when the default address is deleted', function () {
    $customer = User::factory()->customer()->create();
    $default = Address::factory()->forUser($customer)->asDefault()->create();
    $secondary = Address::factory()->forUser($customer)->create();

    $this->actingAs($customer)
        ->delete(route('shop.account.addresses.destroy', $default));

    expect($secondary->fresh()->is_default)->toBeTrue();
});
