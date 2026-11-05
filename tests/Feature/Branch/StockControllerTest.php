<?php

use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockEntry;
use App\Models\StockEntryItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;

/*
 * The branch stock screens (P1-E4-T3, T4, T8).
 */

/**
 * A branch with its default warehouse, which is the shape every branch has
 * after `CreateBranch`.
 *
 * @return array{0: Branch, 1: Warehouse}
 */
function branchWithWarehouse(bool $isDefaultBranch = true): array
{
    $branch = Branch::factory()->create(['is_default' => $isDefaultBranch]);
    $warehouse = Warehouse::factory()->default()->for($branch)->create();

    return [$branch, $warehouse];
}

function variant(?Category $category = null): ProductVariant
{
    $product = Product::factory()
        ->when($category !== null, fn ($factory) => $factory->for($category))
        ->withDefaultVariant()
        ->create();

    return $product->variants()->sole();
}

it('lists every catalogue variant, including the ones the warehouse has never stocked', function () {
    [$branch, $warehouse] = branchWithWarehouse();
    $stocked = variant();
    variant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($stocked)->quantity(12)->create();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->get(route('branch.stocks.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/stocks/index')
            ->has('variants.data', 2)
            ->where('warehouse.id', $warehouse->id)
            ->where('branch.id', $branch->id));
});

it('reports on-hand, reserved, available and the minimum separately', function () {
    [$branch, $warehouse] = branchWithWarehouse();
    $target = variant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($target)
        ->quantity(10)->reserved(4)->minimum(5)->create(['shelf_location' => 'A-01']);

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->get(route('branch.stocks.index'))
        ->assertInertia(fn ($page) => $page
            ->where('variants.data.0.on_hand', 10)
            ->where('variants.data.0.reserved', 4)
            ->where('variants.data.0.available', 6)
            ->where('variants.data.0.min_quantity', 5)
            ->where('variants.data.0.shelf_location', 'A-01')
            ->where('variants.data.0.is_low', false));
});

it('reads a variant with no stock row as zero rather than hiding it', function () {
    [$branch] = branchWithWarehouse();
    variant();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->get(route('branch.stocks.index'))
        ->assertInertia(fn ($page) => $page
            ->where('variants.data.0.on_hand', 0)
            ->where('variants.data.0.available', 0)
            ->where('variants.data.0.is_low', false));
});

it('filters down to the variants below their minimum', function () {
    [$branch, $warehouse] = branchWithWarehouse();
    $low = variant();
    $healthy = variant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($low)->quantity(2)->minimum(5)->create();
    Stock::factory()->inWarehouse($warehouse)->forVariant($healthy)->quantity(50)->minimum(5)->create();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->get(route('branch.stocks.index', ['state' => 'low']))
        ->assertInertia(fn ($page) => $page
            ->has('variants.data', 1)
            ->where('variants.data.0.id', $low->id));
});

it('counts a variant with no stock row as out of stock', function () {
    [$branch, $warehouse] = branchWithWarehouse();
    $never = variant();
    $stocked = variant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($stocked)->quantity(3)->create();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->get(route('branch.stocks.index', ['state' => 'out']))
        ->assertInertia(fn ($page) => $page
            ->has('variants.data', 1)
            ->where('variants.data.0.id', $never->id));
});

it('searches by sku and product name', function () {
    [$branch] = branchWithWarehouse();
    $target = variant();
    variant();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->get(route('branch.stocks.index', ['search' => $target->sku]))
        ->assertInertia(fn ($page) => $page
            ->has('variants.data', 1)
            ->where('variants.data.0.id', $target->id));
});

it('sorts by on-hand quantity, treating a missing row as zero', function () {
    [$branch, $warehouse] = branchWithWarehouse();
    $empty = variant();
    $full = variant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($full)->quantity(99)->create();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->get(route('branch.stocks.index', ['sort' => 'stock_quantity', 'direction' => 'desc']))
        ->assertInertia(fn ($page) => $page
            ->where('variants.data.0.id', $full->id)
            ->where('variants.data.1.id', $empty->id));
});

it('refuses a sort column that is not whitelisted', function () {
    [$branch] = branchWithWarehouse();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->get(route('branch.stocks.index', ['sort' => 'cost_price']))
        ->assertSessionHasErrors('sort');
});

it('shows a branch admin their own warehouse without a branch selector', function () {
    [$branch] = branchWithWarehouse();
    Branch::factory()->create(['is_default' => false]);

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->get(route('branch.stocks.index'))
        ->assertInertia(fn ($page) => $page
            ->where('may_choose_branch', false)
            ->where('branches', []));
});

it('ignores a branch a branch admin asks for that is not theirs', function () {
    [$mine] = branchWithWarehouse();
    [$theirs] = branchWithWarehouse(isDefaultBranch: false);

    $this->actingAs(User::factory()->adminCabang($mine)->create())
        ->get(route('branch.stocks.index', ['branch' => $theirs->id]))
        ->assertInertia(fn ($page) => $page->where('branch.id', $mine->id));
});

it('lets a central actor choose which branch to look at', function () {
    [$default] = branchWithWarehouse();
    [$other] = branchWithWarehouse(isDefaultBranch: false);

    $actor = User::factory()->adminPusat()->create();

    $this->actingAs($actor)
        ->get(route('branch.stocks.index'))
        ->assertInertia(fn ($page) => $page
            ->where('branch.id', $default->id)
            ->where('may_choose_branch', true)
            ->has('branches', 2));

    $this->actingAs($actor)
        ->get(route('branch.stocks.index', ['branch' => $other->id]))
        ->assertInertia(fn ($page) => $page->where('branch.id', $other->id));
});

it('404s on a warehouse that belongs to another branch', function () {
    [$branch] = branchWithWarehouse();
    [, $foreign] = branchWithWarehouse(isDefaultBranch: false);

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->get(route('branch.stocks.index', ['warehouse' => $foreign->id]))
        ->assertNotFound();
});

it('lets a cashier read the screen but not adjust', function () {
    [$branch, $warehouse] = branchWithWarehouse();
    $target = variant();

    $this->actingAs(User::factory()->kasir($branch)->create())
        ->get(route('branch.stocks.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.adjust', false));

    $this->actingAs(User::factory()->kasir($branch)->create())
        ->post(route('branch.stocks.adjust', $target), [
            'warehouse_id' => $warehouse->id,
            'quantity_change' => 5,
            'notes' => 'Coba-coba',
        ])
        ->assertForbidden();
});

it('refuses a customer outright', function () {
    branchWithWarehouse();

    $this->actingAs(User::factory()->customer()->create())
        ->get(route('branch.stocks.index'))
        ->assertForbidden();
});

/*
 * Adjusting a figure by hand (P1-E4-T2 through the panel).
 */

it('adjusts a figure and writes the ledger row behind it', function () {
    [$branch, $warehouse] = branchWithWarehouse();
    $target = variant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($target)->quantity(10)->create();
    $actor = User::factory()->staffGudang($branch)->create();

    $this->actingAs($actor)
        ->post(route('branch.stocks.adjust', $target), [
            'warehouse_id' => $warehouse->id,
            'quantity_change' => -3,
            'notes' => 'Tiga unit rusak',
        ])
        ->assertRedirect();

    $movement = StockMovement::acrossBranches()->sole();

    expect(Stock::acrossBranches()->sole()->quantity)->toBe(7)
        ->and($movement->type)->toBe(StockMovementType::ManualAdjustment)
        ->and($movement->quantity_change)->toBe(-3)
        ->and($movement->created_by)->toBe($actor->getKey());
});

it('requires a reason for an adjustment', function () {
    [$branch, $warehouse] = branchWithWarehouse();
    $target = variant();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('branch.stocks.adjust', $target), [
            'warehouse_id' => $warehouse->id,
            'quantity_change' => 4,
        ])
        ->assertSessionHasErrors('notes');

    expect(StockMovement::acrossBranches()->count())->toBe(0);
});

it('refuses an adjustment of zero, which would explain nothing', function () {
    [$branch, $warehouse] = branchWithWarehouse();
    $target = variant();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('branch.stocks.adjust', $target), [
            'warehouse_id' => $warehouse->id,
            'quantity_change' => 0,
            'notes' => 'Tidak ada perubahan',
        ])
        ->assertSessionHasErrors('quantity_change');
});

it('shows a shortfall next to the number that was typed', function () {
    [$branch, $warehouse] = branchWithWarehouse();
    $target = variant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($target)->quantity(2)->create();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('branch.stocks.adjust', $target), [
            'warehouse_id' => $warehouse->id,
            'quantity_change' => -5,
            'notes' => 'Salah hitung',
        ])
        ->assertSessionHasErrors('quantity_change');

    expect(Stock::acrossBranches()->sole()->quantity)->toBe(2);
});

it('404s when a branch account adjusts stock in another branch', function () {
    [$branch] = branchWithWarehouse();
    [, $foreign] = branchWithWarehouse(isDefaultBranch: false);
    $target = variant();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('branch.stocks.adjust', $target), [
            'warehouse_id' => $foreign->id,
            'quantity_change' => 5,
            'notes' => 'Coba-coba',
        ])
        ->assertNotFound();

    expect(StockMovement::acrossBranches()->count())->toBe(0);
});

/*
 * Reorder thresholds in bulk (P1-E4-T4).
 */

it('sets the minimum for a batch of variants at once', function () {
    [$branch, $warehouse] = branchWithWarehouse();
    $first = variant();
    $second = variant();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->put(route('branch.stocks.thresholds.update'), [
            'warehouse_id' => $warehouse->id,
            'variant_ids' => [$first->id, $second->id],
            'min_quantity' => 5,
            'max_quantity' => 100,
            'shelf_location' => 'B-02',
        ])
        ->assertRedirect();

    $rows = Stock::acrossBranches()->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('min_quantity')->all())->toBe([5, 5])
        ->and($rows->pluck('max_quantity')->all())->toBe([100, 100])
        ->and($rows->pluck('shelf_location')->all())->toBe(['B-02', 'B-02']);
});

it('creates a stock row at zero for a variant that has never been stocked', function () {
    [$branch, $warehouse] = branchWithWarehouse();
    $target = variant();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->put(route('branch.stocks.thresholds.update'), [
            'warehouse_id' => $warehouse->id,
            'variant_ids' => [$target->id],
            'min_quantity' => 3,
        ])
        ->assertRedirect();

    $stock = Stock::acrossBranches()->sole();

    expect($stock->quantity)->toBe(0)
        ->and($stock->min_quantity)->toBe(3)
        ->and(StockMovement::acrossBranches()->count())->toBe(0);
});

it('refuses a maximum below the minimum', function () {
    [$branch, $warehouse] = branchWithWarehouse();
    $target = variant();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->put(route('branch.stocks.thresholds.update'), [
            'warehouse_id' => $warehouse->id,
            'variant_ids' => [$target->id],
            'min_quantity' => 10,
            'max_quantity' => 4,
        ])
        ->assertSessionHasErrors('max_quantity');
});

it('refuses an empty variant selection', function () {
    [$branch, $warehouse] = branchWithWarehouse();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->put(route('branch.stocks.thresholds.update'), [
            'warehouse_id' => $warehouse->id,
            'variant_ids' => [],
            'min_quantity' => 3,
        ])
        ->assertSessionHasErrors('variant_ids');
});

it('404s when a branch account sets thresholds in another branch', function () {
    [$branch] = branchWithWarehouse();
    [, $foreign] = branchWithWarehouse(isDefaultBranch: false);
    $target = variant();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->put(route('branch.stocks.thresholds.update'), [
            'warehouse_id' => $foreign->id,
            'variant_ids' => [$target->id],
            'min_quantity' => 3,
        ])
        ->assertNotFound();
});

/*
 * Movement history (P1-E4-T8).
 */

it('lists one variant movements newest first, with the figures around them', function () {
    [$branch, $warehouse] = branchWithWarehouse();
    $target = variant();
    StockMovement::factory()->inWarehouse($warehouse)->forVariant($target)
        ->change(StockMovementType::EntryIn, 10, 0)
        ->create(['created_at' => now()->subDay()]);
    StockMovement::factory()->inWarehouse($warehouse)->forVariant($target)
        ->change(StockMovementType::IssueOut, -4, 10)
        ->create(['created_at' => now()]);

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->get(route('branch.stocks.movements', $target))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/stocks/movements')
            ->has('movements.data', 2)
            ->where('movements.data.0.quantity_change', -4)
            ->where('movements.data.0.quantity_before', 10)
            ->where('movements.data.0.quantity_after', 6)
            ->where('movements.data.1.quantity_change', 10));
});

it('leaves out movements of other variants and other warehouses', function () {
    [$branch, $warehouse] = branchWithWarehouse();
    [, $elsewhere] = branchWithWarehouse(isDefaultBranch: false);
    $target = variant();
    $other = variant();

    StockMovement::factory()->inWarehouse($warehouse)->forVariant($target)->create();
    StockMovement::factory()->inWarehouse($warehouse)->forVariant($other)->create();
    StockMovement::factory()->inWarehouse($elsewhere)->forVariant($target)->create();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->get(route('branch.stocks.movements', $target))
        ->assertInertia(fn ($page) => $page->has('movements.data', 1));
});

it('filters movement history by type', function () {
    [$branch, $warehouse] = branchWithWarehouse();
    $target = variant();
    StockMovement::factory()->inWarehouse($warehouse)->forVariant($target)
        ->change(StockMovementType::EntryIn, 10)->create();
    StockMovement::factory()->inWarehouse($warehouse)->forVariant($target)
        ->change(StockMovementType::ManualAdjustment, -1, 10)->create();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->get(route('branch.stocks.movements', [$target, 'type' => StockMovementType::EntryIn->value]))
        ->assertInertia(fn ($page) => $page
            ->has('movements.data', 1)
            ->where('movements.data.0.type', 'entry_in'));
});

it('filters movement history by date range', function () {
    [$branch, $warehouse] = branchWithWarehouse();
    $target = variant();
    StockMovement::factory()->inWarehouse($warehouse)->forVariant($target)
        ->create(['created_at' => now()->subMonth()]);
    StockMovement::factory()->inWarehouse($warehouse)->forVariant($target)
        ->create(['created_at' => now()]);

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->get(route('branch.stocks.movements', [
            $target,
            'from' => now()->subDay()->toDateString(),
        ]))
        ->assertInertia(fn ($page) => $page->has('movements.data', 1));
});

it('refuses a date range that ends before it starts', function () {
    [$branch] = branchWithWarehouse();
    $target = variant();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->get(route('branch.stocks.movements', [
            $target,
            'from' => now()->toDateString(),
            'until' => now()->subWeek()->toDateString(),
        ]))
        ->assertSessionHasErrors('until');
});

it('shows the current figures alongside the history', function () {
    [$branch, $warehouse] = branchWithWarehouse();
    $target = variant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($target)
        ->quantity(4)->reserved(1)->minimum(10)->create();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->get(route('branch.stocks.movements', $target))
        ->assertInertia(fn ($page) => $page
            ->where('stock.on_hand', 4)
            ->where('stock.available', 3)
            ->where('stock.is_low', true));
});

it('names the document behind a movement in the history', function () {
    [$branch, $warehouse] = branchWithWarehouse();
    $target = variant();
    $entry = StockEntry::factory()->inWarehouse($warehouse)->create();
    StockEntryItem::factory()->for($entry, 'stockEntry')->forVariant($target)->quantity(9)->create();

    $actor = User::factory()->staffGudang($branch)->create();
    $this->actingAs($actor)->post(route('branch.stock-entries.post', $entry));

    $this->actingAs($actor)
        ->get(route('branch.stocks.movements', $target))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('movements.data', 1)
            ->where('movements.data.0.type', 'entry_in')
            ->where('movements.data.0.reference_label', $entry->code));
});

it('renders the history of a variant whose own name is null', function () {
    [$branch, $warehouse] = branchWithWarehouse();
    $target = variant();
    $target->update(['name' => null]);
    StockMovement::factory()->inWarehouse($warehouse)->forVariant($target)->create();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->get(route('branch.stocks.movements', $target))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('variant.display_name', $target->product->name));
});
