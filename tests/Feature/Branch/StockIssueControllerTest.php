<?php

use App\Enums\StockDocumentStatus;
use App\Enums\StockIssueReason;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockIssue;
use App\Models\StockIssueItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/*
 * Goods out (P1-E4-T6).
 */

/**
 * @return array{0: Branch, 1: Warehouse}
 */
function issueWorkspace(bool $isDefaultBranch = true): array
{
    $branch = Branch::factory()->create(['is_default' => $isDefaultBranch]);

    return [$branch, Warehouse::factory()->default()->for($branch)->create()];
}

function stockedVariant(Warehouse $warehouse, int $quantity = 50): ProductVariant
{
    $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity($quantity)->create();

    return $variant;
}

/**
 * @return array<string, mixed>
 */
function issuePayload(Warehouse $warehouse, ProductVariant $variant, array $overrides = []): array
{
    return [
        'warehouse_id' => $warehouse->id,
        'reason' => StockIssueReason::Damage->value,
        'reference_no' => null,
        'issue_date' => now()->toDateString(),
        'notes' => null,
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 4]],
        ...$overrides,
    ];
}

it('drafts a document without touching stock', function () {
    [$branch, $warehouse] = issueWorkspace();
    $variant = stockedVariant($warehouse);

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('branch.stock-issues.store'), issuePayload($warehouse, $variant))
        ->assertRedirect();

    $issue = StockIssue::acrossBranches()->sole();

    expect($issue->status)->toBe(StockDocumentStatus::Draft)
        ->and($issue->code)->toStartWith('OUT-')
        ->and($issue->reason)->toBe(StockIssueReason::Damage)
        ->and(Stock::acrossBranches()->sole()->quantity)->toBe(50)
        ->and(StockMovement::acrossBranches()->count())->toBe(0);
});

it('takes the stock out when the document is posted', function () {
    [$branch, $warehouse] = issueWorkspace();
    $variant = stockedVariant($warehouse, 30);
    $issue = StockIssue::factory()->inWarehouse($warehouse)->create();
    StockIssueItem::factory()->for($issue, 'stockIssue')->forVariant($variant)->quantity(7)->create();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('branch.stock-issues.post', $issue))
        ->assertRedirect();

    expect(Stock::acrossBranches()->sole()->quantity)->toBe(23)
        ->and(StockMovement::acrossBranches()->sole()->quantity_change)->toBe(-7);
});

it('reports a shortfall on the lines and issues nothing', function () {
    [$branch, $warehouse] = issueWorkspace();
    $variant = stockedVariant($warehouse, 2);
    $issue = StockIssue::factory()->inWarehouse($warehouse)->create();
    StockIssueItem::factory()->for($issue, 'stockIssue')->forVariant($variant)->quantity(9)->create();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('branch.stock-issues.post', $issue))
        ->assertSessionHasErrors('items');

    expect(Stock::acrossBranches()->sole()->quantity)->toBe(2)
        ->and($issue->refresh()->status)->toBe(StockDocumentStatus::Draft);
});

it('requires a reason', function () {
    [$branch, $warehouse] = issueWorkspace();
    $variant = stockedVariant($warehouse);

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('branch.stock-issues.store'), issuePayload($warehouse, $variant, ['reason' => '']))
        ->assertSessionHasErrors('reason');
});

it('requires notes when the reason is "lainnya", which explains nothing by itself', function () {
    [$branch, $warehouse] = issueWorkspace();
    $variant = stockedVariant($warehouse);

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('branch.stock-issues.store'), issuePayload($warehouse, $variant, [
            'reason' => StockIssueReason::Other->value,
        ]))
        ->assertSessionHasErrors('notes');

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('branch.stock-issues.store'), issuePayload($warehouse, $variant, [
            'reason' => StockIssueReason::Other->value,
            'notes' => 'Dipakai untuk display etalase cabang',
        ]))
        ->assertRedirect();
});

it('refuses to edit a posted document', function () {
    [$branch, $warehouse] = issueWorkspace();
    $variant = stockedVariant($warehouse);
    $issue = StockIssue::factory()->inWarehouse($warehouse)->create();
    StockIssueItem::factory()->for($issue, 'stockIssue')->forVariant($variant)->quantity(2)->create();

    $actor = User::factory()->staffGudang($branch)->create();
    $this->actingAs($actor)->post(route('branch.stock-issues.post', $issue));

    $this->actingAs($actor)
        ->put(route('branch.stock-issues.update', $issue->refresh()), issuePayload($warehouse, $variant))
        ->assertForbidden();
});

it('filters the listing by reason', function () {
    [$branch, $warehouse] = issueWorkspace();
    StockIssue::factory()->inWarehouse($warehouse)->because(StockIssueReason::Damage)->create();
    StockIssue::factory()->inWarehouse($warehouse)->because(StockIssueReason::Sample)->create();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->get(route('branch.stock-issues.index', ['reason' => StockIssueReason::Sample->value]))
        ->assertInertia(fn ($page) => $page
            ->has('issues.data', 1)
            ->where('issues.data.0.reason', 'sample'));
});

it('has no delete route at all, so a record of loss cannot disappear', function () {
    [$branch, $warehouse] = issueWorkspace();
    $issue = StockIssue::factory()->inWarehouse($warehouse)->create();

    expect(fn () => route('branch.stock-issues.destroy', $issue))
        ->toThrow(RouteNotFoundException::class);

    // Cancelling is the way out, and it leaves the document visible.
    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('branch.stock-issues.cancel', $issue))
        ->assertRedirect();

    expect($issue->refresh()->status)->toBe(StockDocumentStatus::Cancelled);
});

it('404s on a document of another branch', function () {
    [$branch] = issueWorkspace();
    [, $elsewhere] = issueWorkspace(isDefaultBranch: false);
    $foreign = StockIssue::factory()->inWarehouse($elsewhere)->create();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->get(route('branch.stock-issues.edit', $foreign))
        ->assertNotFound();
});

it('refuses a cashier', function () {
    [$branch] = issueWorkspace();

    $this->actingAs(User::factory()->kasir($branch)->create())
        ->get(route('branch.stock-issues.index'))
        ->assertForbidden();
});
