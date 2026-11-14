<?php

use App\Models\Branch;
use App\Models\BranchProductPrice;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Services\Pricing\EffectivePriceResolver;
use App\Services\Pricing\PriceSource;
use Illuminate\Support\Carbon;

/*
 * Step 2 of the resolver: product promotions (P2-E8-T2, ADR-067).
 *
 * A promotion takes its cut off the catalogue price, outranks the branch's own
 * price, and is picked by priority; the company-wide promotions are the only
 * ones the storefront (no branch) sees.
 */

/**
 * A variant whose catalogue price is `$amount`, on a product with no others.
 */
function promoVariant(int $amount): ProductVariant
{
    $product = Product::factory()->withDefaultVariant()->create(['base_price' => $amount]);

    return $product->variants()->firstOrFail();
}

function promotionOn(ProductVariant $variant, Promotion $promotion): Promotion
{
    $promotion->products()->attach($variant->product_id);

    return $promotion;
}

it('takes a percentage off the catalogue price, rounded down', function () {
    $variant = promoVariant(105_000);
    promotionOn($variant, Promotion::factory()->percentage(15)->create());

    $price = app(EffectivePriceResolver::class)->for($variant);

    // 15% of 105.000 is 15.750 exactly; a 10% share of 105.001 would round down.
    expect($price->amount)->toBe(89_250)
        ->and($price->source)->toBe(PriceSource::Promotion)
        ->and($price->compareAtPrice)->toBe(105_000)
        ->and($price->discountAmount())->toBe(15_750);
});

it('takes a fixed amount off, and never below zero', function () {
    $variant = promoVariant(100_000);
    promotionOn($variant, Promotion::factory()->fixed(30_000)->create());

    expect(app(EffectivePriceResolver::class)->for($variant)->amount)->toBe(70_000);

    $variant->product->promotions()->detach();
    promotionOn($variant, Promotion::factory()->fixed(250_000)->create());

    expect(app(EffectivePriceResolver::class)->for($variant)->amount)->toBe(0);
});

it('lets the higher priority promotion win, and the older one on a tie', function () {
    $variant = promoVariant(100_000);
    $older = Promotion::factory()->percentage(10)->priority(5)->create();
    $newer = Promotion::factory()->percentage(20)->priority(5)->create();
    $higher = Promotion::factory()->percentage(5)->priority(9)->create();

    promotionOn($variant, $older);
    promotionOn($variant, $newer);

    expect(app(EffectivePriceResolver::class)->for($variant)->amount)->toBe(90_000);

    promotionOn($variant, $higher);

    expect(app(EffectivePriceResolver::class)->for($variant)->amount)->toBe(95_000);
});

it('ignores a promotion that is switched off, not yet started or already ended', function () {
    $variant = promoVariant(100_000);
    $now = Carbon::parse('2026-11-14 12:00:00');

    promotionOn($variant, Promotion::factory()->percentage(50)->inactive()->create());
    promotionOn($variant, Promotion::factory()->percentage(40)->between($now->copy()->addDay(), $now->copy()->addDays(2))->create());
    promotionOn($variant, Promotion::factory()->percentage(30)->between($now->copy()->subDays(2), $now->copy()->subDay())->create());

    $price = app(EffectivePriceResolver::class)->for($variant, null, $now);

    expect($price->amount)->toBe(100_000)
        ->and($price->source)->toBe(PriceSource::ProductBasePrice);
});

it('applies a company-wide promotion everywhere and a branch promotion only at its branch', function () {
    $variant = promoVariant(100_000);
    $branch = Branch::factory()->create();
    $other = Branch::factory()->create();

    $named = Promotion::factory()->percentage(25)->create();
    $named->branches()->attach($branch);
    promotionOn($variant, $named);

    $resolver = app(EffectivePriceResolver::class);

    expect($resolver->for($variant, $branch)->amount)->toBe(75_000)
        ->and($resolver->for($variant, $other)->amount)->toBe(100_000)
        ->and($resolver->for($variant, null)->amount)->toBe(100_000);

    $companyWide = Promotion::factory()->percentage(10)->create();
    promotionOn($variant, $companyWide);

    expect($resolver->for($variant, null)->amount)->toBe(90_000)
        ->and($resolver->for($variant, $other)->amount)->toBe(90_000);
});

it('outranks the branch price, taking its cut off the catalogue price', function () {
    $variant = promoVariant(100_000);
    $branch = Branch::factory()->create();
    BranchProductPrice::factory()->for($branch)->for($variant, 'variant')->at(80_000)->create();
    promotionOn($variant, Promotion::factory()->percentage(10)->create());

    $price = app(EffectivePriceResolver::class)->for($variant, $branch);

    expect($price->amount)->toBe(90_000)
        ->and($price->source)->toBe(PriceSource::Promotion);
});

it('prices a page of variants exactly as it prices each one', function () {
    $promoted = promoVariant(100_000);
    $plain = promoVariant(50_000);
    $branch = Branch::factory()->create();
    promotionOn($promoted, Promotion::factory()->percentage(20)->create());

    $resolver = app(EffectivePriceResolver::class);
    $many = $resolver->forMany(
        ProductVariant::query()->with('product')->whereKey([$promoted->id, $plain->id])->get(),
        $branch,
    );

    expect($many[$promoted->id]->amount)->toBe($resolver->for($promoted, $branch)->amount)
        ->and($many[$plain->id]->amount)->toBe($resolver->for($plain, $branch)->amount)
        ->and($many[$promoted->id]->amount)->toBe(80_000);
});
