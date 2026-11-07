<?php

use App\Actions\Order\AssignOrderBranch;
use App\Actions\Order\ReserveStock;
use App\Actions\Order\TransitionOrderStatus;
use App\Enums\BranchStatus;
use App\Enums\OrderStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\CheckoutFailedException;
use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Carbon;

/*
 * Handing an order to another branch (P1-E7-T10).
 *
 * Moving an order means moving the stock it is holding, which is why the
 * destination's holds are taken before the origin's are released: a
 * destination that comes up short must leave the order exactly as it was.
 */

function assignBranch(): AssignOrderBranch
{
    return app(AssignOrderBranch::class);
}

beforeEach(function () {
    $this->origin = Branch::factory()->default()->create();
    $this->originWarehouse = Warehouse::factory()->for($this->origin)->default()->create();
    $this->destination = Branch::factory()->create();
    $this->destinationWarehouse = Warehouse::factory()->for($this->destination)->default()->create();

    /** @var ProductVariant $variant */
    $this->variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
});

/**
 * An unpaid order at the origin branch, holding `$quantity` units there.
 */
function orderHeldAtOrigin(int $quantity = 3, int $originStock = 10): Order
{
    Stock::factory()
        ->inWarehouse(test()->originWarehouse)
        ->forVariant(test()->variant)
        ->quantity($originStock)
        ->create();

    $order = Order::factory()->forBranch(test()->origin)->create();
    OrderItem::factory()->forOrder($order)->forVariant(test()->variant)->quantity($quantity)->create();

    app(ReserveStock::class)->handle($order, [['variant' => test()->variant, 'quantity' => $quantity]]);

    return $order->fresh();
}

function stockAtDestination(int $quantity): void
{
    Stock::factory()
        ->inWarehouse(test()->destinationWarehouse)
        ->forVariant(test()->variant)
        ->quantity($quantity)
        ->create();
}

describe('a successful move', function () {
    it('changes the processing branch', function () {
        $order = orderHeldAtOrigin(3);
        stockAtDestination(10);

        $moved = assignBranch()->handle($order, $this->destination);

        expect($moved->branch_id)->toBe($this->destination->getKey());
    });

    it('moves the hold to the destination and gives the origin its stock back', function () {
        $order = orderHeldAtOrigin(3, originStock: 10);
        stockAtDestination(10);

        assignBranch()->handle($order, $this->destination);

        $byBranch = Stock::acrossBranches()->get()->keyBy('branch_id');

        expect($byBranch[$this->origin->getKey()]->reserved_quantity)->toBe(0)
            ->and($byBranch[$this->origin->getKey()]->quantity)->toBe(10)
            ->and($byBranch[$this->destination->getKey()]->reserved_quantity)->toBe(3)
            ->and($byBranch[$this->destination->getKey()]->quantity)->toBe(10);
    });

    it('releases the old hold and leaves exactly one active', function () {
        $order = orderHeldAtOrigin(3);
        stockAtDestination(10);

        assignBranch()->handle($order, $this->destination);

        $holds = StockReservation::acrossBranches()->get();

        expect($holds)->toHaveCount(2)
            ->and($holds->where('status', ReservationStatus::Released)->sole()->branch_id)
            ->toBe($this->origin->getKey())
            ->and($holds->where('status', ReservationStatus::Active)->sole()->branch_id)
            ->toBe($this->destination->getKey());
    });

    it('keeps the payment window the customer was given', function () {
        Carbon::setTestNow('2026-11-06 10:00:00');
        $order = orderHeldAtOrigin(3);
        stockAtDestination(10);
        $originalExpiry = StockReservation::acrossBranches()->sole()->expires_at;

        Carbon::setTestNow('2026-11-06 10:07:00');
        assignBranch()->handle($order, $this->destination);

        expect(StockReservation::acrossBranches()->active()->sole()->expires_at->toDateTimeString())
            ->toBe($originalExpiry->toDateTimeString());

        Carbon::setTestNow();
    });

    it('notes the move on the timeline without changing the status', function () {
        $order = orderHeldAtOrigin(3);
        stockAtDestination(10);
        $actor = User::factory()->adminPusat()->create();

        assignBranch()->handle($order, $this->destination, $actor);
        $history = $order->statusHistories()->sole();

        expect($history->from_status)->toBe(OrderStatus::PendingPayment)
            ->and($history->to_status)->toBe(OrderStatus::PendingPayment)
            ->and($history->changed_by)->toBe($actor->getKey())
            ->and($history->note)->toContain($this->destination->name);
    });

});

describe('a refused move', function () {
    it('refuses when the destination cannot cover the order, changing nothing', function () {
        $order = orderHeldAtOrigin(5, originStock: 10);
        stockAtDestination(2);

        expect(fn () => assignBranch()->handle($order, $this->destination))
            ->toThrow(InsufficientStockException::class);

        $byBranch = Stock::acrossBranches()->get()->keyBy('branch_id');

        expect(Order::acrossBranches()->sole()->branch_id)->toBe($this->origin->getKey())
            ->and($byBranch[$this->origin->getKey()]->reserved_quantity)->toBe(5)
            ->and($byBranch[$this->destination->getKey()]->reserved_quantity)->toBe(0)
            ->and(StockReservation::acrossBranches()->active()->sole()->branch_id)
            ->toBe($this->origin->getKey());
    });

    it('refuses a destination that is not trading', function () {
        $order = orderHeldAtOrigin(3);
        stockAtDestination(10);
        $this->destination->update(['status' => BranchStatus::Inactive]);

        expect(fn () => assignBranch()->handle($order, $this->destination))
            ->toThrow(CheckoutFailedException::class, 'sedang tidak melayani');
    });

    it('refuses a paid order, because its goods have already left the origin (MED-04)', function () {
        $order = orderHeldAtOrigin(3, originStock: 10);
        app(TransitionOrderStatus::class)->handle($order, OrderStatus::Paid);
        stockAtDestination(10);

        // Moving it used to change `branch_id` and nothing else: the
        // destination got an order it never issued goods for, the origin was
        // short for an order that was no longer its own, and no ledger row
        // explained either side. Bringing the goods across is a stock transfer
        // document (`P2-E3`), so until that exists the move is refused.
        expect(fn () => assignBranch()->handle($order->fresh(), $this->destination))
            ->toThrow(CheckoutFailedException::class, 'sudah dibayar');

        $byBranch = Stock::acrossBranches()->get()->keyBy('branch_id');

        expect(Order::acrossBranches()->sole()->branch_id)->toBe($this->origin->getKey())
            ->and($byBranch[$this->origin->getKey()]->quantity)->toBe(7)
            ->and($byBranch[$this->destination->getKey()]->quantity)->toBe(10)
            ->and(StockReservation::acrossBranches()->sole()->status)->toBe(ReservationStatus::Committed);
    });

    it('refuses a shipped order, because the branch that shipped it is a fact', function () {
        $order = orderHeldAtOrigin(3, originStock: 10);
        $transition = app(TransitionOrderStatus::class);
        $transition->handle($order, OrderStatus::Paid);
        $transition->handle($order->fresh(), OrderStatus::Processing);
        $transition->handle($order->fresh(), OrderStatus::Shipped);
        stockAtDestination(10);

        expect(fn () => assignBranch()->handle($order->fresh(), $this->destination))
            ->toThrow(CheckoutFailedException::class, 'sudah dikirim');
    });

    it('refuses a cancelled order', function () {
        $order = orderHeldAtOrigin(3);
        app(TransitionOrderStatus::class)->handle($order, OrderStatus::Cancelled);
        stockAtDestination(10);

        expect(fn () => assignBranch()->handle($order->fresh(), $this->destination))
            ->toThrow(CheckoutFailedException::class, 'sudah selesai');
    });

    it('refuses a move to the branch the order is already at', function () {
        $order = orderHeldAtOrigin(3);

        expect(fn () => assignBranch()->handle($order, $this->origin))
            ->toThrow(LogicException::class);
    });
});
