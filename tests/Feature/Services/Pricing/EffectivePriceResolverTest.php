<?php

use App\Models\Branch;
use App\Models\BranchProductPrice;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Pricing\EffectivePriceResolver;
use App\Services\Pricing\PriceSource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/*
 * The effective price a buyer pays (P1-E3-T10, BR-8).
 *
 * The priority order under test is the one in docs/02-architecture.md §3:
 * branch price, then variant price, then the product's base price. Flash sales
 * and promotions sit above these and arrive in P1-E8.
 */

function resolver(): EffectivePriceResolver
{
    return app(EffectivePriceResolver::class);
}

function variantOf(int $basePrice, ?int $variantPrice = null, ?int $compareAt = null): ProductVariant
{
    $product = Product::factory()->create([
        'base_price' => $basePrice,
        'compare_at_price' => $compareAt,
    ]);

    return ProductVariant::factory()->for($product)->default()->create(['price' => $variantPrice]);
}

it('falls back to the product base price when nothing else applies', function () {
    $resolved = resolver()->for(variantOf(150_000));

    expect($resolved->amount)->toBe(150_000)
        ->and($resolved->source)->toBe(PriceSource::ProductBasePrice);
});

it('prefers the variant price over the product base price', function () {
    $resolved = resolver()->for(variantOf(150_000, 175_000));

    expect($resolved->amount)->toBe(175_000)
        ->and($resolved->source)->toBe(PriceSource::VariantPrice);
});

it('prefers the branch price over the variant price', function () {
    $variant = variantOf(150_000, 175_000);
    $branch = Branch::factory()->create();
    BranchProductPrice::factory()->for($branch)->for($variant, 'variant')->at(140_000)->create();

    $resolved = resolver()->for($variant, $branch);

    expect($resolved->amount)->toBe(140_000)
        ->and($resolved->source)->toBe(PriceSource::BranchPrice);
});

it('ignores a branch price belonging to another branch', function () {
    $variant = variantOf(150_000);
    $branch = Branch::factory()->create();
    $other = Branch::factory()->create();
    BranchProductPrice::factory()->for($other)->for($variant, 'variant')->at(99_000)->create();

    expect(resolver()->for($variant, $branch)->amount)->toBe(150_000);
});

it('ignores the branch price when no branch is given', function () {
    $variant = variantOf(150_000);
    $branch = Branch::factory()->create();
    BranchProductPrice::factory()->for($branch)->for($variant, 'variant')->at(99_000)->create();

    expect(resolver()->for($variant)->amount)->toBe(150_000);
});

it('ignores a switched-off branch price', function () {
    $variant = variantOf(150_000);
    $branch = Branch::factory()->create();
    BranchProductPrice::factory()->for($branch)->for($variant, 'variant')->at(99_000)->inactive()->create();

    expect(resolver()->for($variant, $branch)->source)->toBe(PriceSource::ProductBasePrice);
});

it('ignores a branch price that has not started yet', function () {
    $variant = variantOf(150_000);
    $branch = Branch::factory()->create();
    BranchProductPrice::factory()->for($branch)->for($variant, 'variant')
        ->at(99_000)->startingAt(now()->addDay())->create();

    expect(resolver()->for($variant, $branch)->amount)->toBe(150_000);
});

it('ignores a branch price that has already ended', function () {
    $variant = variantOf(150_000);
    $branch = Branch::factory()->create();
    BranchProductPrice::factory()->for($branch)->for($variant, 'variant')
        ->at(99_000)->endingAt(now()->subHour())->create();

    expect(resolver()->for($variant, $branch)->amount)->toBe(150_000);
});

it('applies a scheduled branch price inside its window', function () {
    $variant = variantOf(150_000);
    $branch = Branch::factory()->create();
    BranchProductPrice::factory()->for($branch)->for($variant, 'variant')
        ->at(99_000)->between(now()->subDay(), now()->addDay())->create();

    expect(resolver()->for($variant, $branch)->amount)->toBe(99_000);
});

it('answers for a moment other than now', function () {
    $variant = variantOf(150_000);
    $branch = Branch::factory()->create();
    BranchProductPrice::factory()->for($branch)->for($variant, 'variant')
        ->at(99_000)->between(now()->addWeek(), now()->addWeeks(2))->create();

    expect(resolver()->for($variant, $branch)->amount)->toBe(150_000)
        ->and(resolver()->for($variant, $branch, Carbon::now()->addDays(8))->amount)->toBe(99_000);
});

it('carries the catalogue strikethrough price when the branch set none', function () {
    $variant = variantOf(150_000, null, 200_000);
    $branch = Branch::factory()->create();
    BranchProductPrice::factory()->for($branch)->for($variant, 'variant')->at(120_000)->create();

    $resolved = resolver()->for($variant, $branch);

    expect($resolved->compareAtPrice)->toBe(200_000)
        ->and($resolved->discountAmount())->toBe(80_000)
        ->and($resolved->isDiscounted())->toBeTrue();
});

it('prefers the branch strikethrough price over the catalogue one', function () {
    $variant = variantOf(150_000, null, 200_000);
    $branch = Branch::factory()->create();
    BranchProductPrice::factory()->for($branch)->for($variant, 'variant')
        ->at(120_000)->discountedFrom(160_000)->create();

    expect(resolver()->for($variant, $branch)->compareAtPrice)->toBe(160_000);
});

it('reports no discount when the strikethrough price is not higher', function () {
    $variant = variantOf(150_000, null, 150_000);

    $resolved = resolver()->for($variant);

    expect($resolved->discountAmount())->toBeNull()
        ->and($resolved->isDiscounted())->toBeFalse();
});

it('resolves a page of variants without a query per variant', function () {
    $branch = Branch::factory()->create();

    $withBranchPrice = variantOf(150_000);
    $withVariantPrice = variantOf(150_000, 175_000);
    $catalogueOnly = variantOf(150_000);

    BranchProductPrice::factory()->for($branch)->for($withBranchPrice, 'variant')->at(120_000)->create();

    /** @var Collection<int, ProductVariant> $variants */
    $variants = ProductVariant::query()
        ->with('product')
        ->whereKey([$withBranchPrice->id, $withVariantPrice->id, $catalogueOnly->id])
        ->get();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $resolved = resolver()->forMany($variants, $branch);

    // Two queries, whatever the number of variants: the branch prices and the
    // promotions in force (P2-E8-T2 added the second). The guard is the count
    // not growing with the variants, not the exact figure.
    expect($queries)->toBeLessThanOrEqual(2)
        ->and($resolved[$withBranchPrice->id]->amount)->toBe(120_000)
        ->and($resolved[$withBranchPrice->id]->source)->toBe(PriceSource::BranchPrice)
        ->and($resolved[$withVariantPrice->id]->amount)->toBe(175_000)
        ->and($resolved[$withVariantPrice->id]->source)->toBe(PriceSource::VariantPrice)
        ->and($resolved[$catalogueOnly->id]->amount)->toBe(150_000)
        ->and($resolved[$catalogueOnly->id]->source)->toBe(PriceSource::ProductBasePrice);
});

it('resolves many variants the same way as one at a time', function () {
    $branch = Branch::factory()->create();
    $first = variantOf(150_000);
    $second = variantOf(90_000, 95_000);

    BranchProductPrice::factory()->for($branch)->for($first, 'variant')
        ->at(120_000)->between(now()->subDay(), now()->addDay())->create();

    /** @var Collection<int, ProductVariant> $variants */
    $variants = ProductVariant::query()->with('product')->whereKey([$first->id, $second->id])->get();

    $many = resolver()->forMany($variants, $branch);

    foreach ($variants as $variant) {
        $one = resolver()->for($variant, $branch);

        expect($many[$variant->getKey()]->amount)->toBe($one->amount)
            ->and($many[$variant->getKey()]->source)->toBe($one->source);
    }
});

it('resolves nothing for an empty page', function () {
    expect(resolver()->forMany(new Collection, Branch::factory()->create()))->toBe([]);
});

it('prices the branch it is asked about, not the actor own branch', function () {
    // BR-8 is about the branch the *customer* picked. A branch-bound actor
    // must not narrow the resolver to their own branch, or the storefront
    // would price the wrong shop — and a customer has no branch at all.
    $home = Branch::factory()->create();
    $other = Branch::factory()->create();
    $variant = variantOf(150_000);

    BranchProductPrice::factory()->for($other)->for($variant, 'variant')->at(111_000)->create();

    $this->actingAs(User::factory()->adminCabang($home)->create());

    expect(resolver()->for($variant, $other)->amount)->toBe(111_000);
});

it('mirrors isInForce between php and sql', function (callable $build) {
    $branch = Branch::factory()->create();
    $variant = variantOf(150_000);

    /** @var BranchProductPrice $price */
    $price = $build(BranchProductPrice::factory()->for($branch)->for($variant, 'variant')->at(99_000))->create();

    $resolvedFromBranch = resolver()->for($variant, $branch)->source === PriceSource::BranchPrice;

    expect($resolvedFromBranch)->toBe($price->isInForce());
})->with([
    'no schedule' => [fn ($factory) => $factory],
    'switched off' => [fn ($factory) => $factory->inactive()],
    'starts tomorrow' => [fn ($factory) => $factory->startingAt(now()->addDay())],
    'ended an hour ago' => [fn ($factory) => $factory->endingAt(now()->subHour())],
    'inside its window' => [fn ($factory) => $factory->between(now()->subDay(), now()->addDay())],
    'already started, no end' => [fn ($factory) => $factory->startingAt(now()->subDay())],
]);
