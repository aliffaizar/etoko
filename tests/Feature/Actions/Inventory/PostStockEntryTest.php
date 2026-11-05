<?php

use App\Actions\Inventory\PostStockEntry;
use App\Enums\StockDocumentStatus;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockEntry;
use App\Models\StockEntryItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;

function postEntry(): PostStockEntry
{
    return app(PostStockEntry::class);
}

function entryVariant(): ProductVariant
{
    return Product::factory()->withDefaultVariant()->create()->variants()->sole();
}

it('moves the stock of every line and writes a ledger row for each', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $first = entryVariant();
    $second = entryVariant();
    $entry = StockEntry::factory()->inWarehouse($warehouse)->create(['supplier_name' => 'PT Sumber Jaya']);
    StockEntryItem::factory()->for($entry, 'stockEntry')->forVariant($first)->quantity(10)->create();
    StockEntryItem::factory()->for($entry, 'stockEntry')->forVariant($second)->quantity(4)->create();

    $actor = User::factory()->staffGudang($warehouse->branch)->create();
    $posted = postEntry()->handle($entry, $actor);

    expect($posted->status)->toBe(StockDocumentStatus::Posted)
        ->and($posted->posted_by)->toBe($actor->getKey())
        ->and($posted->posted_at)->not->toBeNull()
        // Cast because MySQL returns an aggregate as a string and SQLite as an int.
        ->and((int) Stock::acrossBranches()->sum('quantity'))->toBe(14)
        ->and(StockMovement::acrossBranches()->count())->toBe(2);

    $movement = StockMovement::acrossBranches()->where('product_variant_id', $first->id)->sole();

    expect($movement->type)->toBe(StockMovementType::EntryIn)
        ->and($movement->quantity_change)->toBe(10)
        ->and($movement->reference_type)->toBe($entry->getMorphClass())
        ->and($movement->reference_id)->toBe($entry->getKey())
        ->and($movement->notes)->toBe('Dari PT Sumber Jaya');
});

it('adds to a figure the warehouse already held', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $variant = entryVariant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity(30)->create();
    $entry = StockEntry::factory()->inWarehouse($warehouse)->create();
    StockEntryItem::factory()->for($entry, 'stockEntry')->forVariant($variant)->quantity(5)->create();

    postEntry()->handle($entry);

    expect(Stock::acrossBranches()->sole()->quantity)->toBe(35)
        ->and(StockMovement::acrossBranches()->sole()->quantity_before)->toBe(30);
});

it('carries the line unit cost onto the ledger row', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $entry = StockEntry::factory()->inWarehouse($warehouse)->create();
    StockEntryItem::factory()->for($entry, 'stockEntry')->forVariant(entryVariant())
        ->create(['quantity' => 2, 'unit_cost' => 42_500]);

    postEntry()->handle($entry);

    expect(StockMovement::acrossBranches()->sole()->unit_cost)->toBe(42_500);
});

it('refuses to post the same document twice', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $entry = StockEntry::factory()->inWarehouse($warehouse)->create();
    StockEntryItem::factory()->for($entry, 'stockEntry')->forVariant(entryVariant())->quantity(3)->create();

    postEntry()->handle($entry);

    expect(fn () => postEntry()->handle($entry->refresh()))->toThrow(ValidationException::class);

    expect(Stock::acrossBranches()->sole()->quantity)->toBe(3)
        ->and(StockMovement::acrossBranches()->count())->toBe(1);
});

it('refuses to post a document with no lines', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $entry = StockEntry::factory()->inWarehouse($warehouse)->create();

    expect(fn () => postEntry()->handle($entry))->toThrow(ValidationException::class);

    expect(StockMovement::acrossBranches()->count())->toBe(0);
});

it('refuses to post a cancelled document', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $entry = StockEntry::factory()->inWarehouse($warehouse)->cancelled()->create();
    StockEntryItem::factory()->for($entry, 'stockEntry')->forVariant(entryVariant())->quantity(3)->create();

    expect(fn () => postEntry()->handle($entry))->toThrow(ValidationException::class);
});

it('tells the screen why a document cannot be posted', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $empty = StockEntry::factory()->inWarehouse($warehouse)->create();

    expect($empty->postingBlockedReason())->toContain('minimal satu baris');

    StockEntryItem::factory()->for($empty, 'stockEntry')->forVariant(entryVariant())->quantity(1)->create();

    expect($empty->refresh()->postingBlockedReason())->toBeNull();
});
