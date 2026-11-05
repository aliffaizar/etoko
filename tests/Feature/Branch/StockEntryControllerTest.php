<?php

use App\Enums\StockDocumentStatus;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockEntry;
use App\Models\StockEntryItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;

/*
 * Goods in (P1-E4-T5).
 */

/**
 * @return array{0: Branch, 1: Warehouse}
 */
function entryWorkspace(bool $isDefaultBranch = true): array
{
    $branch = Branch::factory()->create(['is_default' => $isDefaultBranch]);

    return [$branch, Warehouse::factory()->default()->for($branch)->create()];
}

function someEntryVariant(): ProductVariant
{
    return Product::factory()->withDefaultVariant()->create()->variants()->sole();
}

/**
 * @return array<string, mixed>
 */
function entryPayload(Warehouse $warehouse, ProductVariant $variant, array $overrides = []): array
{
    return [
        'warehouse_id' => $warehouse->id,
        'supplier_name' => 'PT Sumber Jaya',
        'reference_no' => 'DO-1234',
        'entry_date' => now()->toDateString(),
        'notes' => null,
        'items' => [
            ['product_variant_id' => $variant->id, 'quantity' => 10, 'unit_cost' => 25_000],
        ],
        ...$overrides,
    ];
}

it('drafts a document without touching stock', function () {
    [$branch, $warehouse] = entryWorkspace();
    $variant = someEntryVariant();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('branch.stock-entries.store'), entryPayload($warehouse, $variant))
        ->assertRedirect();

    $entry = StockEntry::acrossBranches()->sole();

    expect($entry->status)->toBe(StockDocumentStatus::Draft)
        ->and($entry->code)->toStartWith('IN-')
        ->and($entry->items()->count())->toBe(1)
        ->and(Stock::acrossBranches()->count())->toBe(0)
        ->and(StockMovement::acrossBranches()->count())->toBe(0);
});

it('moves the stock only when the document is posted', function () {
    [$branch, $warehouse] = entryWorkspace();
    $variant = someEntryVariant();
    $entry = StockEntry::factory()->inWarehouse($warehouse)->create();
    StockEntryItem::factory()->for($entry, 'stockEntry')->forVariant($variant)->quantity(7)->create();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('branch.stock-entries.post', $entry))
        ->assertRedirect();

    expect(Stock::acrossBranches()->sole()->quantity)->toBe(7)
        ->and($entry->refresh()->status)->toBe(StockDocumentStatus::Posted);
});

it('replaces the lines of a draft rather than merging them', function () {
    [$branch, $warehouse] = entryWorkspace();
    $kept = someEntryVariant();
    $dropped = someEntryVariant();
    $entry = StockEntry::factory()->inWarehouse($warehouse)->create();
    StockEntryItem::factory()->for($entry, 'stockEntry')->forVariant($dropped)->quantity(3)->create();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->put(route('branch.stock-entries.update', $entry), entryPayload($warehouse, $kept))
        ->assertRedirect();

    $items = $entry->refresh()->items;

    expect($items)->toHaveCount(1)
        ->and($items->first()->product_variant_id)->toBe($kept->id);
});

it('refuses to edit a posted document', function () {
    [$branch, $warehouse] = entryWorkspace();
    $variant = someEntryVariant();
    $entry = StockEntry::factory()->inWarehouse($warehouse)->create();
    StockEntryItem::factory()->for($entry, 'stockEntry')->forVariant($variant)->quantity(3)->create();

    $actor = User::factory()->staffGudang($branch)->create();
    $this->actingAs($actor)->post(route('branch.stock-entries.post', $entry));

    $this->actingAs($actor)
        ->put(route('branch.stock-entries.update', $entry->refresh()), entryPayload($warehouse, $variant))
        ->assertForbidden();

    expect(Stock::acrossBranches()->sole()->quantity)->toBe(3);
});

it('refuses to post a document twice', function () {
    [$branch, $warehouse] = entryWorkspace();
    $entry = StockEntry::factory()->inWarehouse($warehouse)->create();
    StockEntryItem::factory()->for($entry, 'stockEntry')->forVariant(someEntryVariant())->quantity(3)->create();

    $actor = User::factory()->staffGudang($branch)->create();
    $this->actingAs($actor)->post(route('branch.stock-entries.post', $entry));

    $this->actingAs($actor)
        ->post(route('branch.stock-entries.post', $entry->refresh()))
        ->assertForbidden();

    expect(Stock::acrossBranches()->sole()->quantity)->toBe(3);
});

it('refuses a document with no lines', function () {
    [$branch, $warehouse] = entryWorkspace();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('branch.stock-entries.store'), entryPayload($warehouse, someEntryVariant(), ['items' => []]))
        ->assertSessionHasErrors('items');
});

it('refuses the same variant twice on one document', function () {
    [$branch, $warehouse] = entryWorkspace();
    $variant = someEntryVariant();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('branch.stock-entries.store'), entryPayload($warehouse, $variant, [
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 2],
                ['product_variant_id' => $variant->id, 'quantity' => 3],
            ],
        ]))
        ->assertSessionHasErrors('items.1.product_variant_id');
});

it('refuses a quantity of zero or less', function () {
    [$branch, $warehouse] = entryWorkspace();
    $variant = someEntryVariant();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('branch.stock-entries.store'), entryPayload($warehouse, $variant, [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 0]],
        ]))
        ->assertSessionHasErrors('items.0.quantity');
});

it('refuses a postdated document, because goods that have not arrived cannot be received', function () {
    [$branch, $warehouse] = entryWorkspace();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('branch.stock-entries.store'), entryPayload($warehouse, someEntryVariant(), [
            'entry_date' => now()->addWeek()->toDateString(),
        ]))
        ->assertSessionHasErrors('entry_date');
});

it('cancels a draft without touching stock', function () {
    [$branch, $warehouse] = entryWorkspace();
    $entry = StockEntry::factory()->inWarehouse($warehouse)->create();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('branch.stock-entries.cancel', $entry))
        ->assertRedirect();

    expect($entry->refresh()->status)->toBe(StockDocumentStatus::Cancelled)
        ->and(StockMovement::acrossBranches()->count())->toBe(0);
});

it('refuses to cancel a posted document', function () {
    [$branch, $warehouse] = entryWorkspace();
    $entry = StockEntry::factory()->inWarehouse($warehouse)->create();
    StockEntryItem::factory()->for($entry, 'stockEntry')->forVariant(someEntryVariant())->quantity(1)->create();

    $actor = User::factory()->staffGudang($branch)->create();
    $this->actingAs($actor)->post(route('branch.stock-entries.post', $entry));

    $this->actingAs($actor)
        ->post(route('branch.stock-entries.cancel', $entry->refresh()))
        ->assertForbidden();
});

it('lists the branch documents with their totals', function () {
    [$branch, $warehouse] = entryWorkspace();
    $entry = StockEntry::factory()->inWarehouse($warehouse)->create();
    StockEntryItem::factory()->for($entry, 'stockEntry')->forVariant(someEntryVariant())->quantity(4)->create();
    StockEntryItem::factory()->for($entry, 'stockEntry')->forVariant(someEntryVariant())->quantity(6)->create();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->get(route('branch.stock-entries.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/stock-entries/index')
            ->has('entries.data', 1)
            ->where('entries.data.0.total_quantity', 10)
            ->where('entries.data.0.item_count', 2)
            ->where('summary.draft', 1));
});

it('leaves out documents of another branch', function () {
    [$branch, $warehouse] = entryWorkspace();
    [, $elsewhere] = entryWorkspace(isDefaultBranch: false);
    StockEntry::factory()->inWarehouse($warehouse)->create();
    StockEntry::factory()->inWarehouse($elsewhere)->create();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->get(route('branch.stock-entries.index'))
        ->assertInertia(fn ($page) => $page->has('entries.data', 1));
});

it('filters documents by status', function () {
    [$branch, $warehouse] = entryWorkspace();
    StockEntry::factory()->inWarehouse($warehouse)->create();
    StockEntry::factory()->inWarehouse($warehouse)->cancelled()->create();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->get(route('branch.stock-entries.index', ['status' => 'cancelled']))
        ->assertInertia(fn ($page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.status', 'cancelled'));
});

it('404s on a document of another branch', function () {
    [$branch] = entryWorkspace();
    [, $elsewhere] = entryWorkspace(isDefaultBranch: false);
    $foreign = StockEntry::factory()->inWarehouse($elsewhere)->create();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->get(route('branch.stock-entries.edit', $foreign))
        ->assertNotFound();
});

it('404s when a branch account drafts into another branch warehouse', function () {
    [$branch] = entryWorkspace();
    [, $elsewhere] = entryWorkspace(isDefaultBranch: false);

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('branch.stock-entries.store'), entryPayload($elsewhere, someEntryVariant()))
        ->assertNotFound();

    expect(StockEntry::acrossBranches()->count())->toBe(0);
});

it('refuses a cashier, who sells stock rather than receiving it', function () {
    [$branch, $warehouse] = entryWorkspace();

    $this->actingAs(User::factory()->kasir($branch)->create())
        ->get(route('branch.stock-entries.index'))
        ->assertForbidden();

    $this->actingAs(User::factory()->kasir($branch)->create())
        ->post(route('branch.stock-entries.store'), entryPayload($warehouse, someEntryVariant()))
        ->assertForbidden();
});

it('deletes a draft but never a posted document', function () {
    [$branch, $warehouse] = entryWorkspace();
    $draft = StockEntry::factory()->inWarehouse($warehouse)->create();
    $posted = StockEntry::factory()->inWarehouse($warehouse)->create();
    StockEntryItem::factory()->for($posted, 'stockEntry')->forVariant(someEntryVariant())->quantity(1)->create();

    // `stock_entries.delete` is a central permission in the baseline.
    $actor = User::factory()->adminPusat()->create();
    $this->actingAs($actor)->post(route('branch.stock-entries.post', $posted));

    $this->actingAs($actor)
        ->delete(route('branch.stock-entries.destroy', $draft))
        ->assertRedirect();

    $this->actingAs($actor)
        ->delete(route('branch.stock-entries.destroy', $posted->refresh()))
        ->assertForbidden();

    expect(StockEntry::acrossBranches()->count())->toBe(1);
});

it('refuses warehouse staff the delete the baseline withholds from them', function () {
    [$branch, $warehouse] = entryWorkspace();
    $draft = StockEntry::factory()->inWarehouse($warehouse)->create();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->delete(route('branch.stock-entries.destroy', $draft))
        ->assertForbidden();
});
