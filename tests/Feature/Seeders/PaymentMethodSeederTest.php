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
 */

it('installs a method for every way of paying the brief asks for', function () {
    $this->seed(PaymentMethodSeeder::class);

    $types = PaymentMethod::query()->pluck('type')->map->value->unique()->sort()->values();

    expect($types->all())->toBe([
        PaymentMethodType::BankTransfer->value,
        PaymentMethodType::Cash->value,
        PaymentMethodType::Cod->value,
        PaymentMethodType::EWallet->value,
        PaymentMethodType::Qris->value,
        PaymentMethodType::VirtualAccount->value,
    ]);
});

it('leaves the gateway methods switched off until there are keys for them', function () {
    $this->seed(PaymentMethodSeeder::class);

    $midtrans = PaymentMethod::query()->where('driver', PaymentDriver::Midtrans)->get();

    expect($midtrans)->not->toBeEmpty()
        ->and($midtrans->every(fn (PaymentMethod $method): bool => $method->is_active === false))
        ->toBeTrue();
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

it('gives a manual transfer an account to transfer into, and says it is a placeholder', function () {
    $this->seed(PaymentMethodSeeder::class);

    $bca = PaymentMethod::query()->where('code', 'bank-transfer-bca')->sole();

    expect($bca->bankAccount())->not->toBeNull()
        ->and($bca->instructions)->toContain('contoh');
});

it('adds nothing on a second run', function () {
    $this->seed(PaymentMethodSeeder::class);
    $before = PaymentMethod::query()->count();

    $this->seed(PaymentMethodSeeder::class);

    expect(PaymentMethod::query()->count())->toBe($before);
});

it('does not undo the shop own decisions when re-run', function () {
    $this->seed(PaymentMethodSeeder::class);

    $bca = PaymentMethod::query()->where('code', 'bank-transfer-bca')->sole();
    $bca->update([
        'is_active' => false,
        'sort_order' => 999,
        'config' => ['bank' => [
            'name' => 'BCA',
            'account_number' => '1122334455',
            'account_holder' => 'PT Toko Sungguhan',
        ]],
    ]);

    $this->seed(PaymentMethodSeeder::class);
    $bca->refresh();

    // A real account number must survive re-seeding; so must a method the shop
    // has deliberately turned off.
    expect($bca->is_active)->toBeFalse()
        ->and($bca->sort_order)->toBe(999)
        ->and($bca->bankAccount()['account_number'])->toBe('1122334455');
});

it('does refresh the parts that are the application own (the name and the words)', function () {
    $this->seed(PaymentMethodSeeder::class);

    PaymentMethod::query()->where('code', 'cod')->update(['name' => 'Nama lama']);

    $this->seed(PaymentMethodSeeder::class);

    expect(PaymentMethod::query()->where('code', 'cod')->sole()->name)
        ->toBe('Bayar di Tempat (COD)');
});
