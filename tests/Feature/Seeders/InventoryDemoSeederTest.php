<?php

use App\Enums\StockDocumentStatus;
use App\Models\Stock;
use App\Models\StockEntry;
use App\Models\StockMovement;
use Database\Seeders\CatalogDemoSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\InventoryDemoSeeder;
use Database\Seeders\RolePermissionSeeder;

/*
 * The inventory demo data (P1-E4).
 *
 * The point of these tests is the invariant, not the fixture: a seeder that
 * wrote `stocks` directly would leave branches holding goods no movement
 * accounts for, which is the one state the epic exists to prevent.
 */

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(DemoDataSeeder::class);
    $this->seed(CatalogDemoSeeder::class);
});

it('gives every branch stock explained by a posted document', function () {
    $this->seed(InventoryDemoSeeder::class);

    $entries = StockEntry::acrossBranches()->get();

    expect($entries)->not->toBeEmpty()
        ->and($entries->every(fn (StockEntry $entry): bool => $entry->status === StockDocumentStatus::Posted))
        ->toBeTrue();
});

it('leaves every stock figure reconcilable against the ledger', function () {
    $this->seed(InventoryDemoSeeder::class);

    $ledgerTotals = StockMovement::acrossBranches()
        ->selectRaw('warehouse_id, product_variant_id, sum(quantity_change) as total')
        ->groupBy('warehouse_id', 'product_variant_id')
        ->get()
        ->mapWithKeys(fn ($row): array => ["{$row->warehouse_id}-{$row->product_variant_id}" => (int) $row->total]);

    $rows = Stock::acrossBranches()->get();

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $stock) {
        $key = "{$stock->warehouse_id}-{$stock->product_variant_id}";

        expect($stock->quantity)->toBe($ledgerTotals[$key] ?? 0);
    }
});

it('leaves a few variants below their minimum, so the alerts have something to show', function () {
    $this->seed(InventoryDemoSeeder::class);

    expect(Stock::acrossBranches()->low()->count())->toBeGreaterThan(0);
});

it('is safe to run twice, leaving the figures the documents explain', function () {
    $this->seed(InventoryDemoSeeder::class);

    $before = (int) Stock::acrossBranches()->sum('quantity');
    $documents = StockEntry::acrossBranches()->count();

    $this->seed(InventoryDemoSeeder::class);

    expect((int) Stock::acrossBranches()->sum('quantity'))->toBe($before)
        ->and(StockEntry::acrossBranches()->count())->toBe($documents);
});

it('records who received and posted each document', function () {
    $this->seed(InventoryDemoSeeder::class);

    $entry = StockEntry::acrossBranches()->first();

    expect($entry->created_by)->not->toBeNull()
        ->and($entry->posted_by)->not->toBeNull()
        ->and($entry->posted_at)->not->toBeNull();
});
