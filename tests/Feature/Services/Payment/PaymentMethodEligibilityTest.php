<?php

use App\Enums\FulfillmentType;
use App\Enums\OrderChannel;
use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Services\Payment\Data\PaymentContext;
use App\Services\Payment\PaymentMethodEligibility;

/*
 * Which ways of paying an order may use (P2-E1-T7).
 *
 * The same rules decide what the checkout screen offers and what
 * `InitiatePayment` accepts, so these are the tests that stop a customer being
 * offered COD and then refused it.
 *
 * Every refusal is asserted as a **sentence**, not merely as false: the whole
 * point of the design is that "COD tidak tersedia" is useless and "total
 * pesanan di atas batas maksimum Rp2.000.000" tells the customer what to do.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->eligibility = app(PaymentMethodEligibility::class);
});

function paymentContext(
    ?Branch $branch = null,
    FulfillmentType $fulfillment = FulfillmentType::Delivery,
    int $amount = 500_000,
    ?string $city = 'Jakarta Selatan',
    ?string $province = 'DKI Jakarta',
    OrderChannel $channel = OrderChannel::Online,
): PaymentContext {
    return new PaymentContext(
        branchId: ($branch ?? test()->branch)->getKey(),
        fulfillmentType: $fulfillment,
        amount: $amount,
        cityName: $city,
        provinceName: $province,
        channel: $channel,
    );
}

it('allows a plain transfer with no fence around it', function () {
    $method = PaymentMethod::factory()->create();

    expect($this->eligibility->reasonAgainst($method, paymentContext()))->toBeNull()
        ->and($this->eligibility->allows($method, paymentContext()))->toBeTrue();
});

it('refuses a method that has been switched off', function () {
    $method = PaymentMethod::factory()->inactive()->create();

    expect($this->eligibility->reasonAgainst($method, paymentContext()))
        ->toContain('dinonaktifkan');
});

it('never offers cash at the till to the storefront', function () {
    $method = PaymentMethod::factory()->cash()->create();

    expect($this->eligibility->reasonAgainst($method, paymentContext()))
        ->toContain('kasir cabang');
});

it('offers cash at the till to the point of sale', function () {
    $method = PaymentMethod::factory()->cash()->create();

    expect($this->eligibility->allows(
        $method,
        paymentContext(channel: OrderChannel::Pos),
    ))->toBeTrue();
});

describe('cash on delivery (P2-E1-T7)', function () {
    it('is offered for a delivery', function () {
        $method = PaymentMethod::factory()->cod()->create();

        expect($this->eligibility->allows($method, paymentContext()))->toBeTrue();
    });

    it('is refused for a pickup, because nobody is there to pay', function () {
        $method = PaymentMethod::factory()->cod()->create();

        expect($this->eligibility->reasonAgainst(
            $method,
            paymentContext(fulfillment: FulfillmentType::Pickup),
        ))->toContain('tidak tersedia untuk ambil');
    });

    it('is refused above its ceiling, and says what the ceiling is', function () {
        $method = PaymentMethod::factory()->cod()->betweenAmounts(null, 2_000_000)->create();

        expect($this->eligibility->reasonAgainst($method, paymentContext(amount: 2_500_001)))
            ->toContain('di atas batas maksimum')
            ->toContain('2.000.000');
    });

    it('is allowed exactly at its ceiling', function () {
        $method = PaymentMethod::factory()->cod()->betweenAmounts(null, 2_000_000)->create();

        expect($this->eligibility->allows($method, paymentContext(amount: 2_000_000)))->toBeTrue();
    });

    it('is refused below a minimum, and says what the minimum is', function () {
        $method = PaymentMethod::factory()->betweenAmounts(100_000, null)->create();

        expect($this->eligibility->reasonAgainst($method, paymentContext(amount: 99_999)))
            ->toContain('di bawah batas minimum')
            ->toContain('100.000');
    });
});

describe('the branch fence (P2-E1-T7)', function () {
    it('allows every branch when no branch is named', function () {
        $method = PaymentMethod::factory()->create();

        expect($this->eligibility->allows($method, paymentContext()))->toBeTrue();
    });

    it('allows a branch that is named', function () {
        $method = PaymentMethod::factory()->forBranches($this->branch)->create();

        expect($this->eligibility->allows($method, paymentContext()))->toBeTrue();
    });

    it('refuses a branch that is not named', function () {
        $elsewhere = Branch::factory()->create();
        $method = PaymentMethod::factory()->forBranches($elsewhere)->create();

        expect($this->eligibility->reasonAgainst($method, paymentContext()))
            ->toContain('belum tersedia di cabang');
    });
});

describe('the destination fence (P2-E1-T7)', function () {
    it('allows a city that is named, ignoring case and padding', function () {
        $method = PaymentMethod::factory()->forCities('  JAKARTA selatan ')->create();

        expect($this->eligibility->allows($method, paymentContext(city: 'Jakarta Selatan')))->toBeTrue();
    });

    it('refuses a city that is not named', function () {
        $method = PaymentMethod::factory()->forCities('Bandung')->create();

        expect($this->eligibility->reasonAgainst($method, paymentContext(city: 'Surabaya')))
            ->toContain('belum melayani wilayah');
    });

    it('accepts a province match even when the city is not listed', function () {
        $method = PaymentMethod::factory()->create([
            'config' => ['cities' => ['Bandung'], 'provinces' => ['DKI Jakarta']],
        ]);

        expect($this->eligibility->allows(
            $method,
            paymentContext(city: 'Jakarta Pusat', province: 'DKI Jakarta'),
        ))->toBeTrue();
    });

    it('exempts a pickup, which has no destination at all', function () {
        $method = PaymentMethod::factory()->forCities('Bandung')->create();

        expect($this->eligibility->allows($method, paymentContext(
            fulfillment: FulfillmentType::Pickup,
            city: null,
            province: null,
        )))->toBeTrue();
    });
});

it('narrows a collection to the methods an order may use', function () {
    $usable = PaymentMethod::factory()->create();
    PaymentMethod::factory()->inactive()->create();
    PaymentMethod::factory()->cash()->create();

    $filtered = $this->eligibility->filter(
        PaymentMethod::query()->orderBy('id')->get(),
        paymentContext(),
    );

    expect($filtered->pluck('id')->all())->toBe([$usable->getKey()]);
});

describe('method fees (ADR-039)', function () {
    it('adds a flat fee and a percentage, rounded once', function () {
        $method = PaymentMethod::factory()->withFee(4_000, 1.5)->create();

        // 4.000 + 1,5% of 333.333 (= 4.999,995, rounded to 5.000).
        expect($method->feeFor(333_333))->toBe(9_000)
            ->and($method->chargeFor(333_333))->toBe(342_333)
            ->and($method->hasFee())->toBeTrue();
    });

    it('charges nothing when the method has no fee', function () {
        $method = PaymentMethod::factory()->create();

        expect($method->feeFor(500_000))->toBe(0)
            ->and($method->chargeFor(500_000))->toBe(500_000)
            ->and($method->hasFee())->toBeFalse();
    });

    it('judges the ceiling against the order, not against the order plus the fee', function () {
        $method = PaymentMethod::factory()->withFee(5_000)->betweenAmounts(null, 500_000)->create();

        // The charge would be 505.000, above the ceiling; the order is not.
        expect($this->eligibility->allows($method, paymentContext(amount: 500_000)))->toBeTrue();
    });
});
