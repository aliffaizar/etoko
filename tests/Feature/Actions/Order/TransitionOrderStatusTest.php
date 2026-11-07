<?php

use App\Actions\Order\ReserveStock;
use App\Actions\Order\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\StockMovementType;
use App\Exceptions\IllegalOrderTransitionException;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;

/*
 * The only way an order's status changes (P1-E7-T7).
 *
 * Four things happen together: the move is checked against the state machine,
 * the stock follows, the timeline gets a row, and the matching timestamp is
 * stamped.
 */

function transitionOrder(): TransitionOrderStatus
{
    return app(TransitionOrderStatus::class);
}

/**
 * An unpaid order holding four units, which is what checkout leaves behind.
 *
 * @return array{0: Order, 1: ProductVariant}
 */
function heldOrder(int $onHand = 10, int $held = 4): array
{
    $warehouse = Warehouse::factory()->default()->create();
    /** @var ProductVariant $variant */
    $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity($onHand)->create();

    $order = Order::factory()->forBranch($warehouse->branch)->create();
    app(ReserveStock::class)->handle($order, [['variant' => $variant, 'quantity' => $held]]);

    return [$order->fresh(), $variant];
}

describe('legal transitions', function () {
    it('allows every edge the state machine declares', function (OrderStatus $from, OrderStatus $to) {
        $order = Order::factory()->create(['status' => $from]);

        expect(transitionOrder()->handle($order, $to)->status)->toBe($to);
    })->with(function () {
        $cases = [];

        foreach (OrderStatus::cases() as $from) {
            foreach ($from->allowedTransitions() as $to) {
                $cases["{$from->value} → {$to->value}"] = [$from, $to];
            }
        }

        return $cases;
    });

    it('stamps the moment the order reached the status', function () {
        $order = Order::factory()->paid()->create(['processed_at' => null]);

        $moved = transitionOrder()->handle($order, OrderStatus::Processing);

        expect($moved->processed_at)->not->toBeNull();
    });

    it('keeps the original moment when a status is reached again', function () {
        // `shipped → cancelled → …` cannot happen, but `paid_at` must survive
        // every later move, because it is when the money arrived.
        $order = Order::factory()->create(['status' => OrderStatus::PendingPayment]);
        $paidAt = now()->subDay();

        transitionOrder()->handle($order, OrderStatus::Paid);
        $order->refresh()->forceFill(['paid_at' => $paidAt])->saveQuietly();

        transitionOrder()->handle($order->fresh(), OrderStatus::Processing);

        expect($order->fresh()->paid_at->toDateTimeString())->toBe($paidAt->toDateTimeString());
    });

    it('marks the payment settled when the order is paid', function () {
        $order = Order::factory()->create();

        $moved = transitionOrder()->handle($order, OrderStatus::Paid);

        expect($moved->payment_status)->toBe(PaymentStatus::Paid)
            ->and($moved->paid_at)->not->toBeNull();
    });

    it('marks the payment expired, with a reason, when the window closes', function () {
        $order = Order::factory()->create();

        $moved = transitionOrder()->handle($order, OrderStatus::Expired);

        expect($moved->payment_status)->toBe(PaymentStatus::Expired)
            ->and($moved->cancel_reason)->toBe('Batas waktu pembayaran terlewat.')
            ->and($moved->cancelled_at)->not->toBeNull();
    });
});

describe('illegal transitions', function () {
    it('refuses a move the state machine has no edge for', function () {
        $order = Order::factory()->create(['status' => OrderStatus::PendingPayment]);

        expect(fn () => transitionOrder()->handle($order, OrderStatus::Shipped))
            ->toThrow(IllegalOrderTransitionException::class);

        expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    });

    it('refuses to move an order to the status it already holds', function () {
        $order = Order::factory()->create(['status' => OrderStatus::Processing]);

        expect(fn () => transitionOrder()->handle($order, OrderStatus::Processing))
            ->toThrow(IllegalOrderTransitionException::class);
    });

    it('refuses to move a final order at all', function (OrderStatus $final) {
        $order = Order::factory()->create(['status' => $final]);

        foreach (OrderStatus::cases() as $target) {
            expect(fn () => transitionOrder()->handle($order, $target))
                ->toThrow(IllegalOrderTransitionException::class);
        }
    })->with([
        'cancelled' => OrderStatus::Cancelled,
        'expired' => OrderStatus::Expired,
        'refunded' => OrderStatus::Refunded,
        'partially refunded' => OrderStatus::PartiallyRefunded,
    ]);

    it('writes nothing at all when it refuses', function () {
        $order = Order::factory()->create(['status' => OrderStatus::Cancelled]);

        try {
            transitionOrder()->handle($order, OrderStatus::Paid);
        } catch (IllegalOrderTransitionException) {
            // Expected.
        }

        expect($order->statusHistories()->count())->toBe(0)
            ->and($order->fresh()->payment_status)->not->toBe(PaymentStatus::Paid);
    });

    it('says what the order could have moved to instead', function () {
        $order = Order::factory()->create(['status' => OrderStatus::PendingPayment]);

        expect(fn () => transitionOrder()->handle($order, OrderStatus::Delivered))
            ->toThrow(IllegalOrderTransitionException::class, 'Yang mungkin:');
    });
});

describe('the timeline', function () {
    it('records every move, with who made it and why', function () {
        $order = Order::factory()->create();
        $actor = User::factory()->adminPusat()->create();

        transitionOrder()->handle($order, OrderStatus::Paid, $actor, 'Transfer BCA diterima');
        $history = $order->statusHistories()->sole();

        expect($history->from_status)->toBe(OrderStatus::PendingPayment)
            ->and($history->to_status)->toBe(OrderStatus::Paid)
            ->and($history->note)->toBe('Transfer BCA diterima')
            ->and($history->changed_by)->toBe($actor->getKey());
    });

    it('records a move nobody was behind', function () {
        $order = Order::factory()->create();

        transitionOrder()->handle($order, OrderStatus::Expired);

        expect($order->statusHistories()->sole()->changed_by)->toBeNull();
    });

    it('keeps the moves in the order they happened', function () {
        $order = Order::factory()->create();

        transitionOrder()->handle($order, OrderStatus::Paid);
        transitionOrder()->handle($order->fresh(), OrderStatus::Processing);
        transitionOrder()->handle($order->fresh(), OrderStatus::Shipped);

        expect($order->statusHistories()->pluck('to_status')->map->value->all())
            ->toBe(['paid', 'processing', 'shipped']);
    });
});

describe('the stock it moves', function () {
    it('takes the goods off the shelf when the order is paid for', function () {
        [$order] = heldOrder(10, 4);

        transitionOrder()->handle($order, OrderStatus::Paid);

        $stock = Stock::acrossBranches()->sole();

        expect($stock->quantity)->toBe(6)
            ->and($stock->reserved_quantity)->toBe(0)
            ->and(StockReservation::acrossBranches()->sole()->status)->toBe(ReservationStatus::Committed)
            ->and(StockMovement::acrossBranches()->where('type', StockMovementType::SaleOut)->count())->toBe(1);
    });

    it('gives the hold back when the order is cancelled', function () {
        [$order] = heldOrder(10, 4);

        transitionOrder()->handle($order, OrderStatus::Cancelled, note: 'Salah pesan');

        $stock = Stock::acrossBranches()->sole();

        expect($stock->quantity)->toBe(10)
            ->and($stock->reserved_quantity)->toBe(0)
            ->and(StockReservation::acrossBranches()->sole()->status)->toBe(ReservationStatus::Released);
    });

    it('gives the hold back when the order expires', function () {
        [$order] = heldOrder(10, 4);

        transitionOrder()->handle($order, OrderStatus::Expired);

        expect(Stock::acrossBranches()->sole()->reserved_quantity)->toBe(0)
            ->and(StockReservation::acrossBranches()->sole()->status)->toBe(ReservationStatus::Released);
    });

    it('does not take the goods off the shelf twice', function () {
        [$order] = heldOrder(10, 4);

        transitionOrder()->handle($order, OrderStatus::Paid);
        transitionOrder()->handle($order->fresh(), OrderStatus::Processing);

        expect(Stock::acrossBranches()->sole()->quantity)->toBe(6);
    });

    it('leaves a sold order alone when it is cancelled afterwards', function () {
        [$order] = heldOrder(10, 4);
        transitionOrder()->handle($order, OrderStatus::Paid);

        // Cancelling a paid order does not put stock back: the goods are
        // already gone, and returning them is a return document (Phase 2).
        transitionOrder()->handle($order->fresh(), OrderStatus::Cancelled, note: 'Pembeli berubah pikiran');

        expect(Stock::acrossBranches()->sole()->quantity)->toBe(6)
            ->and(StockReservation::acrossBranches()->sole()->status)->toBe(ReservationStatus::Committed);
    });

    it('makes the stock buyable again after a cancellation', function () {
        [$order, $variant] = heldOrder(4, 4);

        expect(Stock::acrossBranches()->sole()->available_quantity)->toBe(0);

        transitionOrder()->handle($order, OrderStatus::Cancelled);

        expect(Stock::acrossBranches()->sole()->available_quantity)->toBe(4);
    });
});
