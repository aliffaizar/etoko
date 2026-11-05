<?php

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\BranchProductPrice;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;

/*
 * Branch-specific prices (P1-E3-T10).
 *
 * Writes are central (BR-1 extends to the branch price matrix); a branch admin
 * reads their own branch's prices and nothing else (BR-3).
 */

function branchPriceOf(Branch $branch, ProductVariant $variant): ?BranchProductPrice
{
    return BranchProductPrice::acrossBranches()
        ->where('branch_id', $branch->getKey())
        ->where('product_variant_id', $variant->getKey())
        ->first();
}

function pricedVariant(int $basePrice = 150_000): ProductVariant
{
    return ProductVariant::factory()
        ->for(Product::factory()->create(['base_price' => $basePrice]))
        ->default()
        ->create();
}

it('redirects guests to the login page', function () {
    $this->get(route('admin.branches.prices.index', Branch::factory()->create()))
        ->assertRedirect(route('login'));
});

it('lists every variant with the price in force and where it came from', function () {
    $branch = Branch::factory()->create();
    pricedVariant(150_000);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.branches.prices.index', $branch))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/branches/prices')
            ->has('variants.data', 1)
            ->where('variants.data.0.effective_price', 150_000)
            ->where('variants.data.0.effective_source', 'product_base_price')
            ->where('variants.data.0.branch_price', null)
            ->where('summary.custom', 0));
});

it('reports a branch price as the source once one is in force', function () {
    $branch = Branch::factory()->create();
    $variant = pricedVariant(150_000);
    BranchProductPrice::factory()->for($branch)->for($variant, 'variant')->at(120_000)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.branches.prices.index', $branch))
        ->assertInertia(fn ($page) => $page
            ->where('variants.data.0.effective_price', 120_000)
            ->where('variants.data.0.effective_source', 'branch_price')
            ->where('variants.data.0.catalogue_price', 150_000)
            ->where('variants.data.0.branch_price.price', 120_000)
            ->where('variants.data.0.branch_price.state', 'active')
            ->where('summary.custom', 1)
            ->where('summary.in_force', 1));
});

it('shows a scheduled branch price as not yet in force', function () {
    $branch = Branch::factory()->create();
    $variant = pricedVariant(150_000);
    BranchProductPrice::factory()->for($branch)->for($variant, 'variant')
        ->at(120_000)->startingAt(now()->addWeek())->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.branches.prices.index', $branch))
        ->assertInertia(fn ($page) => $page
            ->where('variants.data.0.effective_price', 150_000)
            ->where('variants.data.0.branch_price.state', 'scheduled')
            ->where('variants.data.0.branch_price.is_in_force', false)
            ->where('summary.in_force', 0));
});

it('lets a branch admin read their own branch', function () {
    $branch = Branch::factory()->create();
    pricedVariant();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->get(route('admin.branches.prices.index', $branch))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.manage', false));
});

it('refuses a branch admin reading another branch', function () {
    $own = Branch::factory()->create();
    $other = Branch::factory()->create();

    $this->actingAs(User::factory()->adminCabang($own)->create())
        ->get(route('admin.branches.prices.index', $other))
        ->assertForbidden();
});

it('refuses roles without branch_prices.view', function (UserRole $role) {
    $branch = Branch::factory()->create();

    $this->actingAs(User::factory()->withRole($role)->forBranch($branch)->create())
        ->get(route('admin.branches.prices.index', $branch))
        ->assertForbidden();
})->with([
    'staff gudang' => [UserRole::StaffGudang],
    'kasir' => [UserRole::Kasir],
    'customer' => [UserRole::Customer],
]);

it('sets a branch price', function () {
    $branch = Branch::factory()->create();
    $variant = pricedVariant(150_000);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.prices.store', [$branch, $variant]), [
            'price' => 120_000,
            'compare_at_price' => 160_000,
            'is_active' => true,
        ])
        ->assertRedirect();

    expect(branchPriceOf($branch, $variant)?->price)->toBe(120_000)
        ->and(branchPriceOf($branch, $variant)?->compare_at_price)->toBe(160_000);
});

it('updates the existing price instead of adding a second one', function () {
    $branch = Branch::factory()->create();
    $variant = pricedVariant();
    BranchProductPrice::factory()->for($branch)->for($variant, 'variant')->at(120_000)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.prices.store', [$branch, $variant]), [
            'price' => 110_000,
            'is_active' => true,
        ]);

    expect(BranchProductPrice::acrossBranches()->where('branch_id', $branch->id)->count())->toBe(1)
        ->and(branchPriceOf($branch, $variant)?->price)->toBe(110_000);
});

it('accepts a price of zero, which is not the same as having none', function () {
    $branch = Branch::factory()->create();
    $variant = pricedVariant();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.prices.store', [$branch, $variant]), [
            'price' => 0,
            'is_active' => true,
        ])
        ->assertRedirect();

    expect(branchPriceOf($branch, $variant)?->price)->toBe(0);
});

it('stores a scheduled window', function () {
    $branch = Branch::factory()->create();
    $variant = pricedVariant();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.prices.store', [$branch, $variant]), [
            'price' => 99_000,
            'starts_at' => '2026-12-01 00:00',
            'ends_at' => '2026-12-31 23:59',
            'is_active' => true,
        ])
        ->assertRedirect();

    expect(branchPriceOf($branch, $variant)?->starts_at?->format('Y-m-d'))->toBe('2026-12-01')
        ->and(branchPriceOf($branch, $variant)?->ends_at?->format('Y-m-d'))->toBe('2026-12-31');
});

it('refuses a window that ends before it starts', function () {
    $branch = Branch::factory()->create();
    $variant = pricedVariant();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.prices.store', [$branch, $variant]), [
            'price' => 99_000,
            'starts_at' => '2026-12-31 00:00',
            'ends_at' => '2026-12-01 00:00',
            'is_active' => true,
        ])
        ->assertSessionHasErrors('ends_at');

    expect(branchPriceOf($branch, $variant))->toBeNull();
});

it('refuses a strikethrough price that is not higher than the price', function () {
    $branch = Branch::factory()->create();
    $variant = pricedVariant();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.prices.store', [$branch, $variant]), [
            'price' => 120_000,
            'compare_at_price' => 120_000,
            'is_active' => true,
        ])
        ->assertSessionHasErrors('compare_at_price');
});

it('refuses a missing price rather than storing zero', function () {
    $branch = Branch::factory()->create();
    $variant = pricedVariant();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.prices.store', [$branch, $variant]), ['is_active' => true])
        ->assertSessionHasErrors('price');
});

it('clears a branch price, putting the variant back on the catalogue price', function () {
    $branch = Branch::factory()->create();
    $variant = pricedVariant(150_000);
    BranchProductPrice::factory()->for($branch)->for($variant, 'variant')->at(120_000)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.branches.prices.destroy', [$branch, $variant]))
        ->assertRedirect();

    expect(branchPriceOf($branch, $variant))->toBeNull();
});

it('404s when clearing a price that was never set', function () {
    $branch = Branch::factory()->create();
    $variant = pricedVariant();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.branches.prices.destroy', [$branch, $variant]))
        ->assertNotFound();
});

it('refuses every write from a branch admin, even for their own branch', function () {
    $branch = Branch::factory()->create();
    $variant = pricedVariant();
    $actor = User::factory()->adminCabang($branch)->create();

    $this->actingAs($actor)
        ->put(route('admin.branches.prices.store', [$branch, $variant]), ['price' => 1_000, 'is_active' => true])
        ->assertForbidden();

    $this->actingAs($actor)
        ->delete(route('admin.branches.prices.destroy', [$branch, $variant]))
        ->assertForbidden();

    expect(branchPriceOf($branch, $variant))->toBeNull();
});

it('leaves other branches alone', function () {
    $branch = Branch::factory()->create();
    $other = Branch::factory()->create();
    $variant = pricedVariant();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.prices.store', [$branch, $variant]), ['price' => 120_000, 'is_active' => true]);

    expect(branchPriceOf($other, $variant))->toBeNull();
});

it('filters to the variants that carry a branch price', function () {
    $branch = Branch::factory()->create();
    $priced = pricedVariant();
    pricedVariant();
    BranchProductPrice::factory()->for($branch)->for($priced, 'variant')->at(120_000)->create();

    $actor = User::factory()->adminPusat()->create();

    $this->actingAs($actor)
        ->get(route('admin.branches.prices.index', [$branch, 'pricing' => 'custom']))
        ->assertInertia(fn ($page) => $page
            ->has('variants.data', 1)
            ->where('variants.data.0.id', $priced->id));

    $this->actingAs($actor)
        ->get(route('admin.branches.prices.index', [$branch, 'pricing' => 'catalogue']))
        ->assertInertia(fn ($page) => $page->has('variants.data', 1));
});

it('searches by sku and by product name', function () {
    $branch = Branch::factory()->create();
    ProductVariant::factory()
        ->for(Product::factory()->create(['name' => 'Kipas Angin Berdiri']))
        ->default()
        ->create(['sku' => 'KPS-16']);
    // Named explicitly: the factory draws from a pool that includes "Kipas
    // Angin Berdiri", which would make the search below match two rows.
    ProductVariant::factory()
        ->for(Product::factory()->create(['name' => 'Tumbler Stainless 750 ml']))
        ->default()
        ->create(['sku' => 'TMB-750']);

    $actor = User::factory()->adminPusat()->create();

    $this->actingAs($actor)
        ->get(route('admin.branches.prices.index', [$branch, 'search' => 'KPS-16']))
        ->assertInertia(fn ($page) => $page->has('variants.data', 1));

    $this->actingAs($actor)
        ->get(route('admin.branches.prices.index', [$branch, 'search' => 'Kipas']))
        ->assertInertia(fn ($page) => $page->has('variants.data', 1));
});

it('refuses a sort column that is not whitelisted', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.branches.prices.index', [$branch, 'sort' => 'price']))
        ->assertSessionHasErrors('sort');
});

it('drops a branch price with the branch', function () {
    $branch = Branch::factory()->create();
    $variant = pricedVariant();
    BranchProductPrice::factory()->for($branch)->for($variant, 'variant')->create();

    $branch->forceDelete();

    expect(BranchProductPrice::acrossBranches()->count())->toBe(0);
});

it('drops a branch price when the variant is force deleted', function () {
    $branch = Branch::factory()->create();
    $variant = pricedVariant();
    BranchProductPrice::factory()->for($branch)->for($variant, 'variant')->create();

    $variant->forceDelete();

    expect(BranchProductPrice::acrossBranches()->count())->toBe(0);
});
