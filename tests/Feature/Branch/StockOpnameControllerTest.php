<?php

use App\Enums\StockOpnameStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\User;
use App\Models\Warehouse;

/*
 * Stock counts (P1-E4-T7).
 */

/**
 * @return array{0: Branch, 1: Warehouse}
 */
function opnameWorkspace(bool $isDefaultBranch = true): array
{
    $branch = Branch::factory()->create(['is_default' => $isDefaultBranch]);

    return [$branch, Warehouse::factory()->default()->for($branch)->create()];
}

function heldVariant(Warehouse $warehouse, int $quantity = 20, ?Category $category = null): ProductVariant
{
    $variant = Product::factory()
        ->when($category !== null, fn ($factory) => $factory->for($category))
        ->withDefaultVariant()
        ->create()
        ->variants()
        ->sole();

    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity($quantity)->create();

    return $variant;
}

it('opens a session with one line per variant the warehouse holds', function () {
    [$branch, $warehouse] = opnameWorkspace();
    heldVariant($warehouse, 20);
    heldVariant($warehouse, 5);
    // A variant the warehouse has never stocked is left out on purpose.
    Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('branch.stock-opnames.store'), [
            'warehouse_id' => $warehouse->id,
            'counted_at' => now()->toDateString(),
        ])
        ->assertRedirect();

    $opname = StockOpname::acrossBranches()->sole();

    expect($opname->status)->toBe(StockOpnameStatus::Draft)
        ->and($opname->code)->toStartWith('OPN-')
        ->and($opname->items()->count())->toBe(2)
        ->and($opname->items()->pluck('system_quantity')->sort()->values()->all())->toBe([5, 20])
        ->and($opname->items()->whereNotNull('counted_quantity')->count())->toBe(0);
});

it('snapshots what the system believed when the session opened', function () {
    [$branch, $warehouse] = opnameWorkspace();
    $variant = heldVariant($warehouse, 12);

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('branch.stock-opnames.store'), [
            'warehouse_id' => $warehouse->id,
            'counted_at' => now()->toDateString(),
        ]);

    // Stock moves after the session opened; the snapshot must not follow it.
    Stock::acrossBranches()->where('product_variant_id', $variant->id)->sole()->update(['quantity' => 3]);

    expect(StockOpnameItem::query()->sole()->system_quantity)->toBe(12);
});

it('narrows a session to one category and its subcategories', function () {
    [$branch, $warehouse] = opnameWorkspace();
    $parent = Category::factory()->create();
    $child = Category::factory()->for($parent, 'parent')->create();
    heldVariant($warehouse, 10, $child);
    heldVariant($warehouse, 10);

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('branch.stock-opnames.store'), [
            'warehouse_id' => $warehouse->id,
            'counted_at' => now()->toDateString(),
            'category_id' => $parent->id,
        ])
        ->assertRedirect();

    expect(StockOpname::acrossBranches()->sole()->items()->count())->toBe(1);
});

it('refuses a session whose scope holds nothing to count', function () {
    [$branch, $warehouse] = opnameWorkspace();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('branch.stock-opnames.store'), [
            'warehouse_id' => $warehouse->id,
            'counted_at' => now()->toDateString(),
        ])
        ->assertSessionHasErrors('category_id');

    expect(StockOpname::acrossBranches()->count())->toBe(0);
});

it('saves counted figures without moving stock, and marks the session in progress', function () {
    [$branch, $warehouse] = opnameWorkspace();
    $variant = heldVariant($warehouse, 20);
    $opname = StockOpname::factory()->inWarehouse($warehouse)->create();
    $item = StockOpnameItem::factory()->for($opname, 'stockOpname')->forVariant($variant)
        ->create(['system_quantity' => 20]);

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->put(route('branch.stock-opnames.counts.update', $opname), [
            'items' => [['id' => $item->id, 'counted_quantity' => 17, 'notes' => 'Tiga dus hilang']],
        ])
        ->assertRedirect();

    expect($item->refresh()->counted_quantity)->toBe(17)
        ->and($item->notes)->toBe('Tiga dus hilang')
        ->and($opname->refresh()->status)->toBe(StockOpnameStatus::InProgress)
        ->and(Stock::acrossBranches()->sole()->quantity)->toBe(20)
        ->and(StockMovement::acrossBranches()->count())->toBe(0);
});

it('lets a counted figure be cleared back to uncounted', function () {
    [$branch, $warehouse] = opnameWorkspace();
    $opname = StockOpname::factory()->inWarehouse($warehouse)->inProgress()->create();
    $item = StockOpnameItem::factory()->for($opname, 'stockOpname')
        ->forVariant(heldVariant($warehouse))->counted(10, 8)->create();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->put(route('branch.stock-opnames.counts.update', $opname), [
            'items' => [['id' => $item->id, 'counted_quantity' => null]],
        ])
        ->assertRedirect();

    expect($item->refresh()->counted_quantity)->toBeNull()
        ->and($item->isCounted())->toBeFalse();
});

it('lets warehouse staff count but not close the session', function () {
    [$branch, $warehouse] = opnameWorkspace();
    $opname = StockOpname::factory()->inWarehouse($warehouse)->inProgress()->create();
    StockOpnameItem::factory()->for($opname, 'stockOpname')
        ->forVariant(heldVariant($warehouse))->counted(20, 18)->create();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->get(route('branch.stock-opnames.show', $opname))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.record_counts', true)
            ->where('can.complete', false));

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('branch.stock-opnames.complete', $opname))
        ->assertForbidden();

    expect($opname->refresh()->status)->toBe(StockOpnameStatus::InProgress);
});

it('lets a branch admin close the session, writing the variances to the ledger', function () {
    [$branch, $warehouse] = opnameWorkspace();
    $variant = heldVariant($warehouse, 20);
    $opname = StockOpname::factory()->inWarehouse($warehouse)->inProgress()->create();
    StockOpnameItem::factory()->for($opname, 'stockOpname')->forVariant($variant)->counted(20, 18)->create();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->post(route('branch.stock-opnames.complete', $opname))
        ->assertRedirect();

    expect($opname->refresh()->status)->toBe(StockOpnameStatus::Completed)
        ->and(Stock::acrossBranches()->sole()->quantity)->toBe(18)
        ->and(StockMovement::acrossBranches()->sole()->quantity_change)->toBe(-2);
});

it('refuses to close a session where nothing was counted', function () {
    [$branch, $warehouse] = opnameWorkspace();
    $opname = StockOpname::factory()->inWarehouse($warehouse)->create();
    StockOpnameItem::factory()->for($opname, 'stockOpname')->forVariant(heldVariant($warehouse))->create();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->post(route('branch.stock-opnames.complete', $opname))
        ->assertSessionHasErrors('status');

    expect($opname->refresh()->status)->toBe(StockOpnameStatus::Draft);
});

it('refuses to type counts into a completed session', function () {
    [$branch, $warehouse] = opnameWorkspace();
    $variant = heldVariant($warehouse, 20);
    $opname = StockOpname::factory()->inWarehouse($warehouse)->inProgress()->create();
    $item = StockOpnameItem::factory()->for($opname, 'stockOpname')->forVariant($variant)->counted(20, 18)->create();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->post(route('branch.stock-opnames.complete', $opname));

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->put(route('branch.stock-opnames.counts.update', $opname->refresh()), [
            'items' => [['id' => $item->id, 'counted_quantity' => 1]],
        ])
        ->assertForbidden();

    expect($item->refresh()->counted_quantity)->toBe(18);
});

it('cancels a session without touching stock', function () {
    [$branch, $warehouse] = opnameWorkspace();
    $opname = StockOpname::factory()->inWarehouse($warehouse)->inProgress()->create();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('branch.stock-opnames.cancel', $opname))
        ->assertRedirect();

    expect($opname->refresh()->status)->toBe(StockOpnameStatus::Cancelled)
        ->and(StockMovement::acrossBranches()->count())->toBe(0);
});

it('shows how far along each session is', function () {
    [$branch, $warehouse] = opnameWorkspace();
    $opname = StockOpname::factory()->inWarehouse($warehouse)->inProgress()->create();
    StockOpnameItem::factory()->for($opname, 'stockOpname')->forVariant(heldVariant($warehouse))->counted(5, 5)->create();
    StockOpnameItem::factory()->for($opname, 'stockOpname')->forVariant(heldVariant($warehouse))->create();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->get(route('branch.stock-opnames.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/stock-opnames/index')
            ->where('opnames.data.0.item_count', 2)
            ->where('opnames.data.0.counted_count', 1));
});

it('filters the listing by the state only a count can be in', function () {
    [$branch, $warehouse] = opnameWorkspace();
    StockOpname::factory()->inWarehouse($warehouse)->create();
    StockOpname::factory()->inWarehouse($warehouse)->inProgress()->create();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->get(route('branch.stock-opnames.index', ['status' => 'in_progress']))
        ->assertInertia(fn ($page) => $page
            ->has('opnames.data', 1)
            ->where('opnames.data.0.status', 'in_progress'));
});

it('404s on a session of another branch', function () {
    [$branch] = opnameWorkspace();
    [, $elsewhere] = opnameWorkspace(isDefaultBranch: false);
    $foreign = StockOpname::factory()->inWarehouse($elsewhere)->create();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->get(route('branch.stock-opnames.show', $foreign))
        ->assertNotFound();
});

it('refuses a cashier', function () {
    [$branch] = opnameWorkspace();

    $this->actingAs(User::factory()->kasir($branch)->create())
        ->get(route('branch.stock-opnames.index'))
        ->assertForbidden();
});
