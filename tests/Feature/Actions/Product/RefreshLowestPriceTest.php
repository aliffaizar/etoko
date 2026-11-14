<?php

use App\Actions\Product\RefreshLowestPrice;
use App\Actions\Promotion\SavePromotion;
use App\Jobs\SyncPromotionPrices;
use App\Models\Branch;
use App\Models\BranchProductPrice;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use Illuminate\Support\Carbon;

/*
 * `products.lowest_price`, the catalogue figure sorting and price filters read
 * (P2-E8-T2, ADR-066). It must equal what the storefront charges at no branch,
 * and it has to follow every change that can move that price.
 */

it('holds the cheapest active variant price, ignoring switched-off variants', function () {
    $product = Product::factory()->create(['base_price' => 100_000]);
    $product->variants()->create(['sku' => 'LP-A', 'price' => 60_000, 'is_default' => true, 'is_active' => true]);
    $product->variants()->create(['sku' => 'LP-B', 'price' => 20_000, 'is_default' => false, 'is_active' => false]);

    app(RefreshLowestPrice::class)->handle($product->getKey());

    expect($product->fresh()->lowest_price)->toBe(60_000);
});

it('is empty when no variant is active', function () {
    $product = Product::factory()->withDefaultVariant()->create(['base_price' => 100_000]);
    $product->variants()->update(['is_active' => false]);

    app(RefreshLowestPrice::class)->handle($product->getKey());

    expect($product->fresh()->lowest_price)->toBeNull();
});

it('ignores branch prices, which the storefront does not charge', function () {
    $product = Product::factory()->withDefaultVariant()->create(['base_price' => 100_000]);
    $variant = $product->variants()->firstOrFail();
    BranchProductPrice::factory()->for(Branch::factory()->create())->for($variant, 'variant')->at(10_000)->create();

    app(RefreshLowestPrice::class)->handle($product->getKey());

    expect($product->fresh()->lowest_price)->toBe(100_000);
});

it('follows a variant that is priced, added or switched off', function () {
    $product = Product::factory()->withDefaultVariant()->create(['base_price' => 100_000]);

    $product->variants()->create(['sku' => 'LP-CHEAP', 'price' => 45_000, 'is_default' => false, 'is_active' => true]);

    expect($product->fresh()->lowest_price)->toBe(45_000);

    $product->variants()->where('sku', 'LP-CHEAP')->first()->update(['is_active' => false]);

    expect($product->fresh()->lowest_price)->toBe(100_000);
});

it('follows a change of the base price', function () {
    $product = Product::factory()->withDefaultVariant()->create(['base_price' => 100_000]);

    $product->update(['base_price' => 90_000]);

    expect($product->fresh()->lowest_price)->toBe(90_000);
});

it('does not touch the product\'s own timestamp when refreshing the price', function () {
    $product = Product::factory()->withDefaultVariant()->create(['base_price' => 100_000]);
    $stamp = $product->fresh()->updated_at;

    Carbon::setTestNow($stamp->copy()->addHour());
    app(RefreshLowestPrice::class)->handle($product->getKey());
    Carbon::setTestNow();

    expect($product->fresh()->updated_at->equalTo($stamp))->toBeTrue();
});

it('moves with a promotion when it is saved, and returns when the product leaves it', function () {
    $product = Product::factory()->withDefaultVariant()->create(['base_price' => 100_000]);
    $promotion = app(SavePromotion::class)->handle([
        'attributes' => ['name' => 'Akhir pekan', 'type' => 'percentage', 'value' => 25, 'priority' => 0, 'starts_at' => null, 'ends_at' => null, 'is_active' => true],
        'product_ids' => [$product->getKey()],
        'branch_ids' => [],
    ]);

    expect($product->fresh()->lowest_price)->toBe(75_000);

    app(SavePromotion::class)->handle([
        'attributes' => ['name' => 'Akhir pekan', 'type' => 'percentage', 'value' => 25, 'priority' => 0, 'starts_at' => null, 'ends_at' => null, 'is_active' => true],
        'product_ids' => [],
        'branch_ids' => [],
    ], $promotion);

    expect($product->fresh()->lowest_price)->toBe(100_000);
});

it('applies a promotion once its start time passes, through the five-minute sweep', function () {
    $start = Carbon::parse('2026-11-14 12:00:00');
    Carbon::setTestNow($start->copy()->subMinutes(30));

    $product = Product::factory()->withDefaultVariant()->create(['base_price' => 100_000]);
    Promotion::factory()->percentage(50)->between($start, $start->copy()->addHours(2))->create()
        ->products()->attach($product->getKey());

    app(RefreshLowestPrice::class)->handle($product->getKey());
    expect($product->fresh()->lowest_price)->toBe(100_000);

    Carbon::setTestNow($start->copy()->addMinutes(3));
    (new SyncPromotionPrices)->handle(app(RefreshLowestPrice::class));

    expect($product->fresh()->lowest_price)->toBe(50_000);

    Carbon::setTestNow();
});

it('refreshes nothing when no promotion starts or ends near now', function () {
    $product = Product::factory()->withDefaultVariant()->create(['base_price' => 100_000]);
    $start = Carbon::parse('2026-11-14 12:00:00');
    Carbon::setTestNow($start);
    Promotion::factory()->percentage(50)->between($start->copy()->addDays(3), $start->copy()->addDays(4))->create()
        ->products()->attach($product->getKey());

    $product->variants()->update(['price' => 70_000]);
    ProductVariant::query()->where('product_id', $product->getKey())->update(['price' => 70_000]);

    (new SyncPromotionPrices)->handle(app(RefreshLowestPrice::class));

    expect($product->fresh()->lowest_price)->toBe(100_000);

    Carbon::setTestNow();
});
