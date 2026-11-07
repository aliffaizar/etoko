<?php

use App\Actions\Order\CommitReservation;
use App\Actions\Order\ReleaseReservation;
use App\Actions\Order\ReserveStock;
use App\Enums\ReservationStatus;
use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\Warehouse;
use App\Services\Branch\BranchContext;
use Illuminate\Support\Carbon;

function reserveStock(): ReserveStock
{
    return app(ReserveStock::class);
}

function releaseReservation(): ReleaseReservation
{
    return app(ReleaseReservation::class);
}

function commitReservation(): CommitReservation
{
    return app(CommitReservation::class);
}

function orderVariant(): ProductVariant
{
    return Product::factory()->withDefaultVariant()->create()->variants()->sole();
}

/**
 * A branch with one warehouse holding `$quantity` of one variant, plus an
 * unpaid order at that branch — the state checkout starts from.
 *
 * @return array{0: Order, 1: ProductVariant, 2: Warehouse}
 */
function orderAwaitingStock(int $quantity = 10): array
{
    $warehouse = Warehouse::factory()->default()->create();
    $variant = orderVariant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity($quantity)->create();
    $order = Order::factory()->forBranch($warehouse->branch)->create();

    return [$order, $variant, $warehouse];
}

describe('ReserveStock', function () {
    it('holds stock without taking anything off the shelf', function () {
        [$order, $variant, $warehouse] = orderAwaitingStock(10);

        reserveStock()->handle($order, [['variant' => $variant, 'quantity' => 3]]);

        $stock = Stock::acrossBranches()->sole();

        expect($stock->quantity)->toBe(10)
            ->and($stock->reserved_quantity)->toBe(3)
            ->and($stock->available_quantity)->toBe(7);
    });

    it('writes a reservation row so the hold can be given back', function () {
        [$order, $variant, $warehouse] = orderAwaitingStock(10);

        reserveStock()->handle($order, [['variant' => $variant, 'quantity' => 4]]);

        $reservation = StockReservation::acrossBranches()->sole();

        expect($reservation->order_id)->toBe($order->getKey())
            ->and($reservation->product_variant_id)->toBe($variant->getKey())
            ->and($reservation->warehouse_id)->toBe($warehouse->getKey())
            ->and($reservation->branch_id)->toBe($warehouse->branch_id)
            ->and($reservation->quantity)->toBe(4)
            ->and($reservation->status)->toBe(ReservationStatus::Active)
            ->and($reservation->expires_at)->not->toBeNull();
    });

    it('records the hold in the ledger with no change to on-hand', function () {
        [$order, $variant] = orderAwaitingStock(10);

        reserveStock()->handle($order, [['variant' => $variant, 'quantity' => 2]]);

        $movement = StockMovement::acrossBranches()->sole();

        expect($movement->type)->toBe(StockMovementType::SaleReservation)
            ->and($movement->quantity_change)->toBe(0)
            ->and($movement->quantity_before)->toBe(10)
            ->and($movement->quantity_after)->toBe(10)
            ->and($movement->reference_id)->toBe($order->getKey());
    });

    it('expires the hold after the configured window', function () {
        config()->set('shop.reservation_minutes', 20);
        Carbon::setTestNow('2026-11-06 10:00:00');
        [$order, $variant] = orderAwaitingStock(10);

        reserveStock()->handle($order, [['variant' => $variant, 'quantity' => 1]]);

        expect(StockReservation::acrossBranches()->sole()->expires_at->toDateTimeString())
            ->toBe('2026-11-06 10:20:00');

        Carbon::setTestNow();
    });

    it('refuses to hold more than the branch has available', function () {
        [$order, $variant] = orderAwaitingStock(5);

        expect(fn () => reserveStock()->handle($order, [['variant' => $variant, 'quantity' => 6]]))
            ->toThrow(InsufficientStockException::class);

        expect(Stock::acrossBranches()->sole()->reserved_quantity)->toBe(0)
            ->and(StockReservation::acrossBranches()->count())->toBe(0);
    });

    it('counts stock another order is already holding as unavailable (BR-6)', function () {
        [$firstOrder, $variant, $warehouse] = orderAwaitingStock(5);
        $secondOrder = Order::factory()->forBranch($warehouse->branch)->create();

        reserveStock()->handle($firstOrder, [['variant' => $variant, 'quantity' => 4]]);

        expect(fn () => reserveStock()->handle($secondOrder, [['variant' => $variant, 'quantity' => 2]]))
            ->toThrow(InsufficientStockException::class);

        expect(Stock::acrossBranches()->sole()->reserved_quantity)->toBe(4);
    });

    it('lets the last unit be held exactly once', function () {
        [$firstOrder, $variant, $warehouse] = orderAwaitingStock(1);
        $secondOrder = Order::factory()->forBranch($warehouse->branch)->create();

        reserveStock()->handle($firstOrder, [['variant' => $variant, 'quantity' => 1]]);

        expect(fn () => reserveStock()->handle($secondOrder, [['variant' => $variant, 'quantity' => 1]]))
            ->toThrow(InsufficientStockException::class);

        expect(StockReservation::acrossBranches()->active()->count())->toBe(1);
    });

    it('holds nothing at all when one line of the order cannot be covered', function () {
        $warehouse = Warehouse::factory()->default()->create();
        $plenty = orderVariant();
        $scarce = orderVariant();
        Stock::factory()->inWarehouse($warehouse)->forVariant($plenty)->quantity(10)->create();
        Stock::factory()->inWarehouse($warehouse)->forVariant($scarce)->quantity(1)->create();
        $order = Order::factory()->forBranch($warehouse->branch)->create();

        expect(fn () => reserveStock()->handle($order, [
            ['variant' => $plenty, 'quantity' => 2],
            ['variant' => $scarce, 'quantity' => 5],
        ]))->toThrow(InsufficientStockException::class);

        expect(Stock::acrossBranches()->sum('reserved_quantity'))->toBe(0)
            ->and(StockReservation::acrossBranches()->count())->toBe(0);
    });

    it('spreads one line across the branch warehouses when no single one covers it', function () {
        $main = Warehouse::factory()->default()->create();
        $overflow = Warehouse::factory()->create(['branch_id' => $main->branch_id, 'is_default' => false]);
        $variant = orderVariant();
        Stock::factory()->inWarehouse($main)->forVariant($variant)->quantity(3)->create();
        Stock::factory()->inWarehouse($overflow)->forVariant($variant)->quantity(4)->create();
        $order = Order::factory()->forBranch($main->branch)->create();

        reserveStock()->handle($order, [['variant' => $variant, 'quantity' => 5]]);

        $byWarehouse = StockReservation::acrossBranches()->pluck('quantity', 'warehouse_id');

        expect($byWarehouse[$main->getKey()])->toBe(3)
            ->and($byWarehouse[$overflow->getKey()])->toBe(2);
    });

    it('draws the default warehouse down first', function () {
        $main = Warehouse::factory()->default()->create();
        $overflow = Warehouse::factory()->create(['branch_id' => $main->branch_id, 'is_default' => false]);
        $variant = orderVariant();
        Stock::factory()->inWarehouse($main)->forVariant($variant)->quantity(5)->create();
        Stock::factory()->inWarehouse($overflow)->forVariant($variant)->quantity(5)->create();
        $order = Order::factory()->forBranch($main->branch)->create();

        reserveStock()->handle($order, [['variant' => $variant, 'quantity' => 4]]);

        expect(StockReservation::acrossBranches()->sole()->warehouse_id)->toBe($main->getKey());
    });

    it('never reaches into another branch stock', function () {
        $here = Warehouse::factory()->default()->create();
        $elsewhere = Warehouse::factory()->default()->create();
        $variant = orderVariant();
        Stock::factory()->inWarehouse($here)->forVariant($variant)->quantity(1)->create();
        Stock::factory()->inWarehouse($elsewhere)->forVariant($variant)->quantity(50)->create();
        $order = Order::factory()->forBranch($here->branch)->create();

        expect(fn () => reserveStock()->handle($order, [['variant' => $variant, 'quantity' => 5]]))
            ->toThrow(InsufficientStockException::class);
    });
});

describe('ReleaseReservation', function () {
    it('gives the hold back without touching on-hand', function () {
        [$order, $variant] = orderAwaitingStock(10);
        reserveStock()->handle($order, [['variant' => $variant, 'quantity' => 4]]);

        $released = releaseReservation()->handleForOrder($order);

        $stock = Stock::acrossBranches()->sole();

        expect($released)->toBe(1)
            ->and($stock->quantity)->toBe(10)
            ->and($stock->reserved_quantity)->toBe(0)
            ->and(StockReservation::acrossBranches()->sole()->status)->toBe(ReservationStatus::Released);
    });

    it('records the release in the ledger', function () {
        [$order, $variant] = orderAwaitingStock(10);
        reserveStock()->handle($order, [['variant' => $variant, 'quantity' => 4]]);

        releaseReservation()->handleForOrder($order);

        $movement = StockMovement::acrossBranches()->latest('id')->first();

        expect($movement->type)->toBe(StockMovementType::SaleReservationRelease)
            ->and($movement->quantity_change)->toBe(0)
            ->and($movement->quantity_after)->toBe(10);
    });

    it('does nothing the second time, rather than driving the figure negative', function () {
        [$order, $variant] = orderAwaitingStock(10);
        reserveStock()->handle($order, [['variant' => $variant, 'quantity' => 4]]);

        releaseReservation()->handleForOrder($order);
        $second = releaseReservation()->handleForOrder($order);

        expect($second)->toBe(0)
            ->and(Stock::acrossBranches()->sole()->reserved_quantity)->toBe(0);
    });

    it('leaves a committed hold alone', function () {
        [$order, $variant] = orderAwaitingStock(10);
        reserveStock()->handle($order, [['variant' => $variant, 'quantity' => 4]]);
        commitReservation()->handleForOrder($order);

        expect(releaseReservation()->handleForOrder($order))->toBe(0)
            ->and(Stock::acrossBranches()->sole()->quantity)->toBe(6);
    });

    it('makes the stock buyable again', function () {
        [$firstOrder, $variant, $warehouse] = orderAwaitingStock(1);
        $secondOrder = Order::factory()->forBranch($warehouse->branch)->create();
        reserveStock()->handle($firstOrder, [['variant' => $variant, 'quantity' => 1]]);

        releaseReservation()->handleForOrder($firstOrder);
        reserveStock()->handle($secondOrder, [['variant' => $variant, 'quantity' => 1]]);

        expect(StockReservation::acrossBranches()->active()->sole()->order_id)
            ->toBe($secondOrder->getKey());
    });
});

describe('CommitReservation', function () {
    it('moves on-hand and the hold down together', function () {
        [$order, $variant] = orderAwaitingStock(10);
        reserveStock()->handle($order, [['variant' => $variant, 'quantity' => 4]]);

        $committed = commitReservation()->handleForOrder($order);

        $stock = Stock::acrossBranches()->sole();

        expect($committed)->toBe(1)
            ->and($stock->quantity)->toBe(6)
            ->and($stock->reserved_quantity)->toBe(0)
            ->and($stock->available_quantity)->toBe(6);
    });

    it('records the sale in the ledger, pointing at the order', function () {
        [$order, $variant] = orderAwaitingStock(10);
        reserveStock()->handle($order, [['variant' => $variant, 'quantity' => 4]]);

        commitReservation()->handleForOrder($order);

        $movement = StockMovement::acrossBranches()->latest('id')->first();

        expect($movement->type)->toBe(StockMovementType::SaleOut)
            ->and($movement->quantity_change)->toBe(-4)
            ->and($movement->quantity_before)->toBe(10)
            ->and($movement->quantity_after)->toBe(6)
            ->and($movement->reference_type)->toBe((new Order)->getMorphClass())
            ->and($movement->reference_id)->toBe($order->getKey());
    });

    it('marks the hold committed and drops its expiry', function () {
        [$order, $variant] = orderAwaitingStock(10);
        reserveStock()->handle($order, [['variant' => $variant, 'quantity' => 4]]);

        commitReservation()->handleForOrder($order);

        $reservation = StockReservation::acrossBranches()->sole();

        expect($reservation->status)->toBe(ReservationStatus::Committed)
            ->and($reservation->expires_at)->toBeNull();
    });

    it('does nothing the second time', function () {
        [$order, $variant] = orderAwaitingStock(10);
        reserveStock()->handle($order, [['variant' => $variant, 'quantity' => 4]]);

        commitReservation()->handleForOrder($order);

        expect(commitReservation()->handleForOrder($order))->toBe(0)
            ->and(Stock::acrossBranches()->sole()->quantity)->toBe(6);
    });

    it('cannot commit a hold that was already released', function () {
        [$order, $variant] = orderAwaitingStock(10);
        reserveStock()->handle($order, [['variant' => $variant, 'quantity' => 4]]);
        releaseReservation()->handleForOrder($order);

        expect(commitReservation()->handleForOrder($order))->toBe(0)
            ->and(Stock::acrossBranches()->sole()->quantity)->toBe(10);
    });

    it('commits every warehouse the order drew from', function () {
        $main = Warehouse::factory()->default()->create();
        $overflow = Warehouse::factory()->create(['branch_id' => $main->branch_id, 'is_default' => false]);
        $variant = orderVariant();
        Stock::factory()->inWarehouse($main)->forVariant($variant)->quantity(3)->create();
        Stock::factory()->inWarehouse($overflow)->forVariant($variant)->quantity(4)->create();
        $order = Order::factory()->forBranch($main->branch)->create();
        reserveStock()->handle($order, [['variant' => $variant, 'quantity' => 5]]);

        expect(commitReservation()->handleForOrder($order))->toBe(2);

        $byWarehouse = Stock::acrossBranches()->pluck('quantity', 'warehouse_id');

        expect($byWarehouse[$main->getKey()])->toBe(0)
            ->and($byWarehouse[$overflow->getKey()])->toBe(2)
            ->and(Stock::acrossBranches()->sum('reserved_quantity'))->toBe(0);
    });
});

it('keeps a branch from seeing another branch holds (BR-3)', function () {
    [$order, $variant, $warehouse] = orderAwaitingStock(10);
    reserveStock()->handle($order, [['variant' => $variant, 'quantity' => 2]]);
    $otherBranch = Branch::factory()->create();

    app(BranchContext::class)->restrictTo($otherBranch->getKey());

    expect(StockReservation::query()->count())->toBe(0);
});
