<?php

use App\Enums\PaymentDriver;
use App\Enums\PaymentMethodType;
use App\Models\PaymentMethod;
use Database\Seeders\PaymentMethodSeeder;

/*
 * The payment methods every install needs (P2-E1-T1).
 *
 * Reference data rather than demo content — a shop with no payment methods has
 * a checkout that cannot be completed — so this runs outside the
 * `isProduction()` guard, and re-running it must not undo a shop's own
 * decisions.
 *
 * Online payment is Midtrans and only Midtrans (ADR-051); what stands beside it
 * is money handed over in person. The key is set explicitly in each test that
 * depends on it, because whether the gateway rows arrive switched on is now a
 * function of configuration rather than a constant.
 */

const SEEDER_MIDTRANS_KEY = 'SB-Mid-server-SEEDERKEY';

it('installs a method for every way of paying the brief asks for', function () {
    $this->seed(PaymentMethodSeeder::class);

    $types = PaymentMethod::query()->pluck('type')->map->value->unique()->sort()->values();

    expect($types->all())->toBe([
        PaymentMethodType::Cash->value,
        PaymentMethodType::Cod->value,
        PaymentMethodType::EWallet->value,
        PaymentMethodType::Qris->value,
        PaymentMethodType::VirtualAccount->value,
    ]);
});

it('leaves the gateway methods switched off when there is no key for them', function () {
    config()->set('services.midtrans.server_key', null);

    $this->seed(PaymentMethodSeeder::class);

    $midtrans = PaymentMethod::query()->where('driver', PaymentDriver::Midtrans)->get();

    expect($midtrans)->not->toBeEmpty()
        ->and($midtrans->every(fn (PaymentMethod $method): bool => $method->is_active === false))
        ->toBeTrue();
});

it('switches the gateway methods on when a key is configured', function () {
    config()->set('services.midtrans.server_key', SEEDER_MIDTRANS_KEY);

    $this->seed(PaymentMethodSeeder::class);

    $midtrans = PaymentMethod::query()->where('driver', PaymentDriver::Midtrans)->get();

    expect($midtrans)->toHaveCount(6)
        ->and($midtrans->every(fn (PaymentMethod $method): bool => $method->is_active === true))
        ->toBeTrue();
});

it('installs no manual bank transfer method', function () {
    config()->set('services.midtrans.server_key', SEEDER_MIDTRANS_KEY);

    $this->seed(PaymentMethodSeeder::class);

    expect(PaymentMethod::query()->where('type', PaymentMethodType::BankTransfer)->exists())->toBeFalse();
});

it('leaves a pickup order payable, which is what a gateway key buys', function () {
    config()->set('services.midtrans.server_key', SEEDER_MIDTRANS_KEY);

    $this->seed(PaymentMethodSeeder::class);

    // COD is fenced to deliveries, so without the gateway rows switched on a
    // Click & Collect order would have nothing to pay with at all.
    $online = PaymentMethod::query()
        ->active()
        ->get()
        ->filter(fn (PaymentMethod $method): bool => $method->type->isAvailableOnline()
            && $method->allowedFulfillmentTypes() !== ['delivery']);

    expect($online)->not->toBeEmpty();
});

it('switches on the methods that need no keys', function () {
    $this->seed(PaymentMethodSeeder::class);

    expect(PaymentMethod::query()->active()->where('driver', PaymentDriver::Manual)->count())
        ->toBeGreaterThan(0);
});

it('fences COD to deliveries, with a ceiling', function () {
    $this->seed(PaymentMethodSeeder::class);

    $cod = PaymentMethod::query()->where('code', 'cod')->sole();

    expect($cod->allowedFulfillmentTypes())->toBe(['delivery'])
        ->and($cod->max_amount)->toBeGreaterThan(0);
});

it('says in words what a COD customer has to have ready', function () {
    $this->seed(PaymentMethodSeeder::class);

    $cod = PaymentMethod::query()->where('code', 'cod')->sole();

    expect($cod->instructions)->toContain('tunai');
});

it('adds nothing on a second run', function () {
    $this->seed(PaymentMethodSeeder::class);
    $before = PaymentMethod::query()->count();

    $this->seed(PaymentMethodSeeder::class);

    expect(PaymentMethod::query()->count())->toBe($before);
});

it('does not undo the shop own decisions when re-run', function () {
    config()->set('services.midtrans.server_key', SEEDER_MIDTRANS_KEY);

    $this->seed(PaymentMethodSeeder::class);

    $va = PaymentMethod::query()->where('code', 'midtrans-va')->sole();
    $va->update([
        'is_active' => false,
        'sort_order' => 999,
        'config' => ['branch_ids' => [7]],
    ]);

    $this->seed(PaymentMethodSeeder::class);
    $va->refresh();

    // A method the shop has deliberately turned off must stay off even though
    // the key is configured, and the branches it was fenced to must survive too.
    expect($va->is_active)->toBeFalse()
        ->and($va->sort_order)->toBe(999)
        ->and($va->allowedBranchIds())->toBe([7]);
});

it('does refresh the parts that are the application own (the name and the words)', function () {
    $this->seed(PaymentMethodSeeder::class);

    PaymentMethod::query()->where('code', 'cod')->update(['name' => 'Nama lama']);

    $this->seed(PaymentMethodSeeder::class);

    expect(PaymentMethod::query()->where('code', 'cod')->sole()->name)
        ->toBe('Bayar di Tempat (COD)');
});
