<?php

use App\Support\Money;

it('formats an amount as rupiah with thousand separators', function () {
    expect(Money::format(1500000))->toBe('Rp 1.500.000');
});

it('formats zero as rupiah', function () {
    expect(Money::format(0))->toBe('Rp 0');
});

it('rounds a percentage down to the nearest rupiah', function () {
    expect(Money::percentage(1999, 10))->toBe(199);
});

it('rejects a percentage outside zero and one hundred', function (float $percent) {
    expect(fn () => Money::percentage(1000, $percent))->toThrow(InvalidArgumentException::class);
})->with([
    'negative' => [-1.0],
    'above one hundred' => [100.1],
]);

it('never returns a negative amount when subtracting a percentage', function () {
    expect(Money::subtractPercentage(0, 100))->toBe(0);
});

it('subtracts a percentage from an amount', function () {
    expect(Money::subtractPercentage(200000, 15))->toBe(170000);
});

it('caps a discount at the amount being discounted', function () {
    expect(Money::cap(500000, 120000))->toBe(120000);
});

it('caps a negative discount at zero', function () {
    expect(Money::cap(-5000, 120000))->toBe(0);
});
