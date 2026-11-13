<?php

use App\Enums\StockMovementType;
use App\Enums\StockTransferStatus;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\StockTransferNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Stock transfers between branches, over HTTP (P2-E3-T3, T4, T5).
 */

beforeEach(function () {
    $this->origin = Branch::factory()->default()->create(['name' => 'Cabang Asal']);
    $this->destination = Branch::factory()->create(['name' => 'Cabang Tujuan']);
    $this->outsider = Branch::factory()->create(['name' => 'Cabang Lain']);

    $this->fromWarehouse = Warehouse::factory()->default()->for($this->origin)->create();
    $this->toWarehouse = Warehouse::factory()->default()->for($this->destination)->create();
    Warehouse::factory()->default()->for($this->outsider)->create();

    $this->variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
    Stock::factory()->inWarehouse($this->fromWarehouse)->forVariant($this->variant)->quantity(30)->create();
});

function requestedTransfer(Warehouse $from, Warehouse $to, ProductVariant $variant, int $quantity = 5): StockTransfer
{
    $transfer = StockTransfer::factory()->between($from, $to)->create();
    StockTransferItem::factory()->for($transfer)->forVariant($variant)->requested($quantity)->create();

    return $transfer;
}

/**
 * @return array{items: array<int, array{id: int, quantity: int}>}
 */
function everyLine(StockTransfer $transfer, int $quantity): array
{
    return ['items' => $transfer->items()->get()->map(fn ($item) => ['id' => $item->id, 'quantity' => $quantity])->all()];
}

function onHandAt(Warehouse $warehouse, ProductVariant $variant): int
{
    return (int) Stock::acrossBranches()->where('warehouse_id', $warehouse->id)
        ->where('product_variant_id', $variant->id)->value('quantity');
}

it('carries a transfer from request to receipt, each step by its own end', function () {
    Notification::fake();
    $requester = User::factory()->adminCabang($this->destination)->create();
    $originAdmin = User::factory()->adminCabang($this->origin)->create();

    $this->actingAs($requester)
        ->post(route('branch.stock-transfers.store'), [
            'warehouse_id' => $this->toWarehouse->id,
            'from_branch_id' => $this->origin->id,
            'notes' => 'Stok menipis',
            'items' => [['product_variant_id' => $this->variant->id, 'quantity' => 6]],
        ])
        ->assertRedirect();

    $transfer = StockTransfer::acrossBranches()->sole();
    Notification::assertSentTo($originAdmin, StockTransferNotification::class);

    $this->actingAs($originAdmin)
        ->post(route('branch.stock-transfers.approve', $transfer), everyLine($transfer, 6))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($originAdmin)
        ->post(route('branch.stock-transfers.ship', $transfer), everyLine($transfer, 6))
        ->assertSessionHasNoErrors();

    expect(onHandAt($this->fromWarehouse, $this->variant))->toBe(24)
        ->and(onHandAt($this->toWarehouse, $this->variant))->toBe(0);

    $this->actingAs($requester)
        ->post(route('branch.stock-transfers.receive', $transfer), everyLine($transfer, 6))
        ->assertSessionHasNoErrors();

    expect($transfer->refresh()->status)->toBe(StockTransferStatus::Received)
        ->and(onHandAt($this->toWarehouse, $this->variant))->toBe(6)
        ->and(StockMovement::acrossBranches()->pluck('type')->all())
        ->toBe([StockMovementType::TransferOut, StockMovementType::TransferIn]);
});

it('lists only the transfers the branch is a party to, marked by direction', function () {
    $incoming = requestedTransfer($this->fromWarehouse, $this->toWarehouse, $this->variant);
    $outgoing = requestedTransfer($this->toWarehouse, $this->fromWarehouse, $this->variant);
    $outsiderWarehouse = Warehouse::acrossBranches()->where('branch_id', $this->outsider->id)->sole();
    requestedTransfer($this->fromWarehouse, $outsiderWarehouse, $this->variant);

    $this->actingAs(User::factory()->adminCabang($this->destination)->create())
        ->get(route('branch.stock-transfers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('branch/stock-transfers/index')
            ->has('transfers.data', 2)
            ->where('transfers.data.0.id', $outgoing->id)
            ->where('transfers.data.0.direction', 'outgoing')
            ->where('transfers.data.1.id', $incoming->id)
            ->where('transfers.data.1.direction', 'incoming')
            ->where('summary.to_approve', 1)
            ->where('summary.waiting_on_others', 1));
});

it('lets central staff see every transfer, or stand in one branch', function () {
    requestedTransfer($this->fromWarehouse, $this->toWarehouse, $this->variant);
    $outsiderWarehouse = Warehouse::acrossBranches()->where('branch_id', $this->outsider->id)->sole();
    requestedTransfer($this->fromWarehouse, $outsiderWarehouse, $this->variant);
    $central = User::factory()->adminPusat()->create();

    $this->actingAs($central)
        ->get(route('branch.stock-transfers.index'))
        ->assertInertia(fn (Assert $page) => $page->has('transfers.data', 2)->where('perspective', null));

    $this->actingAs($central)
        ->get(route('branch.stock-transfers.index', ['branch' => $this->outsider->id, 'direction' => 'incoming']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('transfers.data', 1)
            ->where('perspective.id', $this->outsider->id)
            ->where('transfers.data.0.direction', 'incoming'));
});

it('answers a third branch with 404 on every route of a transfer', function (string $method, string $route) {
    $transfer = requestedTransfer($this->fromWarehouse, $this->toWarehouse, $this->variant);
    $outsider = User::factory()->adminCabang($this->outsider)->create();

    $this->actingAs($outsider)
        ->{$method}(route($route, $transfer), everyLine($transfer, 1) + ['reason' => 'Bukan urusan kami'])
        ->assertNotFound();

    expect($transfer->refresh()->status)->toBe(StockTransferStatus::Requested);
})->with([
    ['get', 'branch.stock-transfers.show'],
    ['post', 'branch.stock-transfers.approve'],
    ['post', 'branch.stock-transfers.reject'],
    ['post', 'branch.stock-transfers.cancel'],
]);

it('refuses the destination approving its own request', function () {
    $transfer = requestedTransfer($this->fromWarehouse, $this->toWarehouse, $this->variant);

    $this->actingAs(User::factory()->adminCabang($this->destination)->create())
        ->post(route('branch.stock-transfers.approve', $transfer), everyLine($transfer, 5))
        ->assertForbidden();

    expect($transfer->refresh()->status)->toBe(StockTransferStatus::Requested);
});

it('refuses a cashier at the door', function () {
    $this->actingAs(User::factory()->kasir($this->destination)->create())
        ->get(route('branch.stock-transfers.index'))
        ->assertForbidden();
});

it('does not let branch staff request on behalf of another branch', function () {
    $this->actingAs(User::factory()->adminCabang($this->outsider)->create())
        ->post(route('branch.stock-transfers.store'), [
            'warehouse_id' => $this->toWarehouse->id,
            'from_branch_id' => $this->origin->id,
            'items' => [['product_variant_id' => $this->variant->id, 'quantity' => 1]],
        ])
        ->assertNotFound();

    expect(StockTransfer::acrossBranches()->count())->toBe(0);
});

it('asks for at least one line and a reason when turning a request down', function () {
    $transfer = requestedTransfer($this->fromWarehouse, $this->toWarehouse, $this->variant);
    $originAdmin = User::factory()->adminCabang($this->origin)->create();

    $this->actingAs(User::factory()->adminCabang($this->destination)->create())
        ->post(route('branch.stock-transfers.store'), [
            'warehouse_id' => $this->toWarehouse->id,
            'from_branch_id' => $this->origin->id,
            'items' => [],
        ])
        ->assertSessionHasErrors(['items' => 'Tambahkan minimal satu baris barang.']);

    $this->actingAs($originAdmin)
        ->post(route('branch.stock-transfers.reject', $transfer), ['reason' => ''])
        ->assertSessionHasErrors(['reason' => 'Tulis alasan penolakan — alasan ini dibaca cabang peminta.']);
});

it('reports a shortfall at shipping on the lines and moves nothing', function () {
    $transfer = StockTransfer::factory()->between($this->fromWarehouse, $this->toWarehouse)->approved()->create();
    StockTransferItem::factory()->for($transfer)->forVariant($this->variant)->requested(40)->approved()->create();

    $this->actingAs(User::factory()->adminCabang($this->origin)->create())
        ->post(route('branch.stock-transfers.ship', $transfer), everyLine($transfer, 40))
        ->assertSessionHasErrors('items');

    expect(onHandAt($this->fromWarehouse, $this->variant))->toBe(30)
        ->and($transfer->refresh()->status)->toBe(StockTransferStatus::Approved);
});

it('shows the origin shelf only to the end that decides and packs', function () {
    $transfer = requestedTransfer($this->fromWarehouse, $this->toWarehouse, $this->variant);

    $this->actingAs(User::factory()->adminCabang($this->origin)->create())
        ->get(route('branch.stock-transfers.show', $transfer))
        ->assertInertia(fn (Assert $page) => $page
            ->component('branch/stock-transfers/show')
            ->where('transfer.items.0.origin_on_hand', 30)
            ->where('can.approve', true)
            ->has('originWarehouses', 1));

    $this->actingAs(User::factory()->adminCabang($this->destination)->create())
        ->get(route('branch.stock-transfers.show', $transfer))
        ->assertInertia(fn (Assert $page) => $page
            ->where('transfer.items.0.origin_on_hand', null)
            ->where('can.approve', false)
            ->where('can.cancel', true)
            ->has('originWarehouses', 0));
});

it('records a short receipt with the receiver explanation', function () {
    $transfer = StockTransfer::factory()->between($this->fromWarehouse, $this->toWarehouse)->inTransit()->create();
    StockTransferItem::factory()->for($transfer)->forVariant($this->variant)->requested(5)->shipped()->create();

    $this->actingAs(User::factory()->adminCabang($this->destination)->create())
        ->post(route('branch.stock-transfers.receive', $transfer), everyLine($transfer, 3) + [
            'receipt_notes' => 'Dua unit pecah',
        ])
        ->assertSessionHasNoErrors();

    $transfer->refresh();

    expect($transfer->status)->toBe(StockTransferStatus::PartiallyReceived)
        ->and($transfer->receipt_notes)->toBe('Dua unit pecah')
        ->and(onHandAt($this->toWarehouse, $this->variant))->toBe(3);
});
