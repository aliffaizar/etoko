<?php

use App\Actions\Inventory\PostStockIssue;
use App\Enums\StockDocumentStatus;
use App\Enums\StockIssueReason;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockIssue;
use App\Models\StockIssueItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;

function postIssue(): PostStockIssue
{
    return app(PostStockIssue::class);
}

function issueVariant(): ProductVariant
{
    return Product::factory()->withDefaultVariant()->create()->variants()->sole();
}

it('takes the stock out and records the reason on the ledger row', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $variant = issueVariant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity(20)->create();
    $issue = StockIssue::factory()->inWarehouse($warehouse)->because(StockIssueReason::Damage)->create();
    StockIssueItem::factory()->for($issue, 'stockIssue')->forVariant($variant)->quantity(6)->create();

    $actor = User::factory()->staffGudang($warehouse->branch)->create();
    $posted = postIssue()->handle($issue, $actor);

    $movement = StockMovement::acrossBranches()->sole();

    expect($posted->status)->toBe(StockDocumentStatus::Posted)
        ->and(Stock::acrossBranches()->sole()->quantity)->toBe(14)
        ->and($movement->type)->toBe(StockMovementType::IssueOut)
        // Positive on the line, negative in the ledger: the direction belongs
        // to the document.
        ->and($movement->quantity_change)->toBe(-6)
        ->and($movement->notes)->toBe('Rusak')
        ->and($movement->created_by)->toBe($actor->getKey());
});

it('issues nothing at all when one line is short', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $plenty = issueVariant();
    $scarce = issueVariant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($plenty)->quantity(100)->create();
    Stock::factory()->inWarehouse($warehouse)->forVariant($scarce)->quantity(1)->create();

    $issue = StockIssue::factory()->inWarehouse($warehouse)->create();
    StockIssueItem::factory()->for($issue, 'stockIssue')->forVariant($plenty)->quantity(5)->create();
    StockIssueItem::factory()->for($issue, 'stockIssue')->forVariant($scarce)->quantity(9)->create();

    expect(fn () => postIssue()->handle($issue))->toThrow(ValidationException::class);

    expect(Stock::acrossBranches()->where('product_variant_id', $plenty->id)->sole()->quantity)->toBe(100)
        ->and(StockMovement::acrossBranches()->count())->toBe(0)
        ->and($issue->refresh()->status)->toBe(StockDocumentStatus::Draft);
});

it('refuses to issue stock that checkout is already holding', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $variant = issueVariant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)
        ->quantity(10)->reserved(8)->create();
    $issue = StockIssue::factory()->inWarehouse($warehouse)->create();
    StockIssueItem::factory()->for($issue, 'stockIssue')->forVariant($variant)->quantity(5)->create();

    expect(fn () => postIssue()->handle($issue))->toThrow(ValidationException::class);

    expect(Stock::acrossBranches()->sole()->quantity)->toBe(10);
});

it('refuses to post the same document twice', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $variant = issueVariant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity(20)->create();
    $issue = StockIssue::factory()->inWarehouse($warehouse)->create();
    StockIssueItem::factory()->for($issue, 'stockIssue')->forVariant($variant)->quantity(2)->create();

    postIssue()->handle($issue);

    expect(fn () => postIssue()->handle($issue->refresh()))->toThrow(ValidationException::class);

    expect(Stock::acrossBranches()->sole()->quantity)->toBe(18);
});

it('refuses to post a document with no lines', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $issue = StockIssue::factory()->inWarehouse($warehouse)->create();

    expect(fn () => postIssue()->handle($issue))->toThrow(ValidationException::class);
});
