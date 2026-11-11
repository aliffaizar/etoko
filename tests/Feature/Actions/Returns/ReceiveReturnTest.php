<?php

use App\Actions\Order\ReserveStock;
use App\Actions\Order\TransitionOrderStatus;
use App\Actions\Returns\ApproveReturn;
use App\Actions\Returns\ReceiveReturn;
use App\Enums\OrderStatus;
use App\Enums\ReturnItemCondition;
use App\Enums\ReturnStatus;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnItem;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;

/*
 * Booking a returned parcel in (P2-E4-T3).
 *
 * The only place in this epic that moves stock, so these are the tests that
 * matter most: every unit that goes back on a shelf has to be explainable by a
 * `sale_return_in` ledger row (ADR-024), and no unit may go back twice.
 *
 * This is also the restock path `ADR-035` promised when it left cancelling a
 * paid order without one.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    $this->warehouse = Warehouse::factory()->for($this->branch)->default()->create();
    $this->staff = User::factory()->adminCabang($this->branch)->create();
    $this->customer = User::factory()->customer()->create();
    $this->action = app(ReceiveReturn::class);
});

/**
 * A delivered order whose goods really left this branch's shelf, with an
 * approved return against `$returned` of them.
 *
 * Driven through `ReserveStock` and `TransitionOrderStatus` so the committed
 * hold that tells `ReceiveReturn` which warehouse to restock into is a real
 * one.
 *
 * @return array{0: Order, 1: OrderItem, 2: OrderReturn, 3: ProductVariant}
 */
function approvedReturn(int $bought = 3, int $returned = 2, int $onHand = 10): array
{
    /** @var ProductVariant $variant */
    $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
    Stock::factory()->inWarehouse(test()->warehouse)->forVariant($variant)->quantity($onHand)->create();

    $order = Order::factory()
        ->forBranch(test()->branch)
        ->forCustomer(test()->customer)
        ->create(['shipping_cost' => 20_000]);

    $item = OrderItem::factory()
        ->forOrder($order)
        ->forVariant($variant)
        ->quantity($bought)
        ->create();

    app(ReserveStock::class)->handle($order, [['variant' => $variant, 'quantity' => $bought]]);

    $transition = app(TransitionOrderStatus::class);
    $transition->handle($order->fresh(), OrderStatus::Paid);
    $transition->handle($order->fresh(), OrderStatus::Processing);
    $transition->handle($order->fresh(), OrderStatus::Shipped);
    $transition->handle($order->fresh(), OrderStatus::Delivered);

    $return = OrderReturn::factory()->forOrder($order->fresh())->create();
    ReturnItem::factory()->forReturn($return)->forOrderItem($item, $returned)->create();

    app(ApproveReturn::class)->handle($return, test()->staff);

    return [$order->fresh(), $item->fresh(), $return->fresh(), $variant];
}

describe('putting goods back on the shelf', function () {
    it('restocks sellable units through the ledger, into the warehouse they left from', function () {
        [$order, , $return, $variant] = approvedReturn(bought: 3, returned: 2, onHand: 10);

        // The sale took three off the shelf: 10 − 3 = 7.
        expect(Stock::query()->where('product_variant_id', $variant->id)->sole()->quantity)->toBe(7);

        $this->action->handle($return, [], $this->staff);

        $stock = Stock::query()->where('product_variant_id', $variant->id)->sole();

        expect($stock->quantity)->toBe(9)
            ->and($stock->warehouse_id)->toBe($this->warehouse->id)
            ->and($this->action->restockedUnits)->toBe(2)
            ->and($this->action->restockedLines)->toBe(1);

        $movement = StockMovement::query()
            ->where('type', StockMovementType::SaleReturnIn)
            ->sole();

        expect($movement->quantity_change)->toBe(2)
            ->and($movement->quantity_before)->toBe(7)
            ->and($movement->quantity_after)->toBe(9)
            ->and($movement->warehouse_id)->toBe($this->warehouse->id)
            ->and($movement->reference_type)->toBe($return->getMorphClass())
            ->and($movement->reference_id)->toBe($return->id)
            ->and($movement->created_by)->toBe($this->staff->id)
            ->and($movement->notes)->toContain($order->order_number);
    });

    it('records where each line went back and when', function () {
        [, , $return] = approvedReturn();

        $this->action->handle($return, [], $this->staff);

        $line = $return->fresh()->items->first();

        expect($line->restocked_at)->not->toBeNull()
            ->and($line->restock_warehouse_id)->toBe($this->warehouse->id);
    });

    it('moves the request to received with who received it', function () {
        [, , $return] = approvedReturn();

        $this->action->handle($return, [], $this->staff);
        $return->refresh();

        expect($return->status)->toBe(ReturnStatus::Received)
            ->and($return->received_by)->toBe($this->staff->id)
            ->and($return->received_at)->not->toBeNull();
    });

    it('writes a note on the order timeline saying how many units came back', function () {
        [$order, , $return] = approvedReturn(returned: 2);

        $this->action->handle($return, [], $this->staff);

        $note = OrderStatusHistory::query()
            ->where('order_id', $order->id)
            ->orderByDesc('id')
            ->firstOrFail();

        expect($note->from_status)->toBe($note->to_status)
            ->and($note->note)->toContain($return->code)
            ->and($note->note)->toContain('2 unit');
    });
});

describe('what does not go back on the shelf', function () {
    it('leaves damaged units off the shelf whatever the restock flag says', function () {
        [, , $return, $variant] = approvedReturn(bought: 3, returned: 2, onHand: 10);

        $this->action->handle($return, [[
            'id' => $return->items->first()->id,
            'condition' => ReturnItemCondition::Damaged,
            // Deliberately contradicting the condition: the condition wins.
            'restock' => true,
            'notes' => 'Dus hancur, isi pecah.',
        ]], $this->staff);

        $line = $return->fresh()->items->first();

        expect(Stock::query()->where('product_variant_id', $variant->id)->sole()->quantity)->toBe(7)
            ->and($line->condition)->toBe(ReturnItemCondition::Damaged)
            ->and($line->restock)->toBeFalse()
            ->and($line->restocked_at)->toBeNull()
            ->and($line->notes)->toBe('Dus hancur, isi pecah.')
            ->and(StockMovement::query()->where('type', StockMovementType::SaleReturnIn)->count())->toBe(0)
            ->and($this->action->restockedUnits)->toBe(0);
    });

    it('still takes damaged units off what the order counts as sold', function () {
        [, $item, $return] = approvedReturn(bought: 3, returned: 2);

        $this->action->handle($return, [[
            'id' => $return->items->first()->id,
            'condition' => ReturnItemCondition::Damaged,
            'restock' => false,
            'notes' => null,
        ]], $this->staff);

        $item->refresh();

        expect($item->refunded_quantity)->toBe(2)
            ->and($item->netQuantity())->toBe(1);
    });

    it('leaves a line alone when the branch ticks restock off', function () {
        [, , $return, $variant] = approvedReturn(bought: 3, returned: 2, onHand: 10);

        $this->action->handle($return, [[
            'id' => $return->items->first()->id,
            'condition' => ReturnItemCondition::Good,
            'restock' => false,
            'notes' => null,
        ]], $this->staff);

        expect(Stock::query()->where('product_variant_id', $variant->id)->sole()->quantity)->toBe(7);
    });

    it('ignores a submitted line that is not part of this request', function () {
        [, , $return, $variant] = approvedReturn(bought: 3, returned: 2, onHand: 10);

        $this->action->handle($return, [[
            'id' => 99_999,
            'condition' => ReturnItemCondition::Damaged,
            'restock' => false,
            'notes' => null,
        ]], $this->staff);

        // The stale line was dropped, so the request's own line kept what the
        // customer asked for and still went back on the shelf.
        expect(Stock::query()->where('product_variant_id', $variant->id)->sole()->quantity)->toBe(9);
    });
});

describe('what the order counts as sold', function () {
    it('raises refunded_quantity by what came back, without touching the money columns', function () {
        [$order, $item, $return] = approvedReturn(bought: 3, returned: 2);

        $subtotal = $order->subtotal;
        $grandTotal = $order->grand_total;
        $lineSubtotal = $item->subtotal;

        $this->action->handle($return, [], $this->staff);

        $order->refresh();
        $item->refresh();

        expect($item->refunded_quantity)->toBe(2)
            ->and($item->quantity)->toBe(3)
            ->and($item->subtotal)->toBe($lineSubtotal)
            ->and($order->subtotal)->toBe($subtotal)
            ->and($order->grand_total)->toBe($grandTotal)
            ->and($order->payment_status->value)->toBe('paid');
    });
});

describe('refusals and retries', function () {
    it('refuses a request nobody has approved', function () {
        /** @var ProductVariant $variant */
        $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
        $order = Order::factory()->forBranch($this->branch)->delivered()->create();
        $item = OrderItem::factory()->forOrder($order)->forVariant($variant)->quantity(1)->create();

        $return = OrderReturn::factory()->forOrder($order->fresh())->create();
        ReturnItem::factory()->forReturn($return)->forOrderItem($item)->create();

        expect(fn () => $this->action->handle($return, [], $this->staff))
            ->toThrow(ValidationException::class, 'tidak dapat diubah menjadi');
    });

    it('refuses to book the same parcel in twice', function () {
        [, , $return] = approvedReturn();

        $this->action->handle($return, [], $this->staff);

        expect(fn () => $this->action->handle($return->fresh(), [], $this->staff))
            ->toThrow(ValidationException::class);
    });

    it('never restocks the same units twice even if a line is replayed', function () {
        [, , $return, $variant] = approvedReturn(bought: 3, returned: 2, onHand: 10);

        $this->action->handle($return, [], $this->staff);

        // Force the request back to `approved` as though a retry reached it,
        // which the state machine does not allow but a bug might.
        $return->fresh()->forceFill(['status' => ReturnStatus::Approved])->saveQuietly();

        $this->action->handle($return->fresh(), [], $this->staff);

        expect(Stock::query()->where('product_variant_id', $variant->id)->sole()->quantity)->toBe(9)
            ->and(StockMovement::query()->where('type', StockMovementType::SaleReturnIn)->count())->toBe(1);
    });
});
