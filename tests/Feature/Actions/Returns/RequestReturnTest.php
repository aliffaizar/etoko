<?php

use App\Actions\Returns\ApproveReturn;
use App\Actions\Returns\CancelReturn;
use App\Actions\Returns\RejectReturn;
use App\Actions\Returns\RequestReturn;
use App\Enums\ReturnItemCondition;
use App\Enums\ReturnReason;
use App\Enums\ReturnStatus;
use App\Enums\ReturnType;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;

/*
 * Opening a return request (P2-E4-T2).
 *
 * The customer's end of the flow. Nothing moves here — no stock, no money, no
 * order status — so what the tests are about is which requests the application
 * refuses, and that the units a live request has committed cannot be committed
 * twice.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    Warehouse::factory()->for($this->branch)->default()->create();
    $this->customer = User::factory()->customer()->create();
    $this->action = app(RequestReturn::class);
});

/**
 * A delivered order with one line of `$quantity` units.
 *
 * @return array{0: Order, 1: OrderItem}
 */
function deliveredOrder(int $quantity = 3, int $unitPrice = 100_000): array
{
    $order = Order::factory()
        ->forBranch(test()->branch)
        ->forCustomer(test()->customer)
        ->delivered()
        ->create(['shipping_cost' => 20_000]);

    $item = OrderItem::factory()
        ->forOrder($order)
        ->quantity($quantity)
        ->unitPrice($unitPrice)
        ->create();

    $order->refresh();

    return [$order, $item];
}

/**
 * @param  array<int, array{order_item_id: int, quantity: int}>  $items
 * @return array{type: ReturnType, reason: ReturnReason, description: string|null, photo_paths: array<int, string>, items: array<int, array{order_item_id: int, quantity: int}>}
 */
function returnRequest(array $items, ReturnReason $reason = ReturnReason::WrongItem): array
{
    return [
        'type' => ReturnType::Refund,
        'reason' => $reason,
        'description' => 'Warnanya tidak sesuai pesanan.',
        'photo_paths' => [],
        'items' => $items,
    ];
}

describe('opening a request', function () {
    it('writes a numbered document with its lines and nothing else', function () {
        [$order, $item] = deliveredOrder(quantity: 3);

        $return = $this->action->handle(
            $order,
            $this->customer,
            returnRequest([['order_item_id' => $item->id, 'quantity' => 2]]),
        );

        expect($return->code)->toStartWith('RTN-')
            ->and($return->status)->toBe(ReturnStatus::Requested)
            ->and($return->branch_id)->toBe($this->branch->id)
            ->and($return->user_id)->toBe($this->customer->id)
            ->and($return->requested_at)->not->toBeNull()
            ->and($return->items)->toHaveCount(1)
            ->and($return->items->first()->quantity)->toBe(2);

        // Nothing has moved: the order is where it was and the line still
        // counts every unit as sold.
        $order->refresh();
        $item->refresh();

        expect($order->status->value)->toBe('delivered')
            ->and($order->payment_status->value)->toBe('paid')
            ->and($item->refunded_quantity)->toBe(0);
    });

    it('copies the branch from the order so the branch that sent the goods decides', function () {
        [$order, $item] = deliveredOrder();

        $return = $this->action->handle(
            $order,
            $this->customer,
            returnRequest([['order_item_id' => $item->id, 'quantity' => 1]]),
        );

        expect($return->branch_id)->toBe($order->branch_id);
    });

    it('marks lines damaged and not for restock when the reason is damage', function () {
        [$order, $item] = deliveredOrder();

        $return = $this->action->handle(
            $order,
            $this->customer,
            returnRequest(
                [['order_item_id' => $item->id, 'quantity' => 1]],
                ReturnReason::Damaged,
            ),
        );

        $line = $return->items->first();

        expect($line->condition)->toBe(ReturnItemCondition::Damaged)
            ->and($line->restock)->toBeFalse();
    });

    it('keeps the photos the customer attached', function () {
        [$order, $item] = deliveredOrder();

        $return = $this->action->handle($order, $this->customer, [
            ...returnRequest([['order_item_id' => $item->id, 'quantity' => 1]]),
            'photo_paths' => ['return-photos/1/a.jpg', 'return-photos/1/b.jpg'],
        ]);

        expect($return->photo_paths)->toBe(['return-photos/1/a.jpg', 'return-photos/1/b.jpg']);
    });
});

describe('refusals', function () {
    it('refuses an order whose goods have not reached the customer', function () {
        $order = Order::factory()
            ->forBranch($this->branch)
            ->forCustomer($this->customer)
            ->processing()
            ->create();

        $item = OrderItem::factory()->forOrder($order)->quantity(1)->create();

        expect(fn () => $this->action->handle(
            $order->refresh(),
            $this->customer,
            returnRequest([['order_item_id' => $item->id, 'quantity' => 1]]),
        ))->toThrow(ValidationException::class, 'belum dapat diretur');
    });

    it('refuses an order that was never paid for', function () {
        $order = Order::factory()
            ->forBranch($this->branch)
            ->forCustomer($this->customer)
            ->delivered()
            ->create(['payment_status' => 'unpaid']);

        $item = OrderItem::factory()->forOrder($order)->quantity(1)->create();

        expect(fn () => $this->action->handle(
            $order->refresh(),
            $this->customer,
            returnRequest([['order_item_id' => $item->id, 'quantity' => 1]]),
        ))->toThrow(ValidationException::class);
    });

    it('refuses a line that belongs to another order', function () {
        [$order] = deliveredOrder();
        [, $foreign] = deliveredOrder();

        expect(fn () => $this->action->handle(
            $order,
            $this->customer,
            returnRequest([['order_item_id' => $foreign->id, 'quantity' => 1]]),
        ))->toThrow(ValidationException::class, 'bukan bagian dari pesanan ini');
    });

    it('refuses more units than were bought', function () {
        [$order, $item] = deliveredOrder(quantity: 2);

        expect(fn () => $this->action->handle(
            $order,
            $this->customer,
            returnRequest([['order_item_id' => $item->id, 'quantity' => 3]]),
        ))->toThrow(ValidationException::class, 'Maksimal 2 unit');
    });

    it('refuses a request with no lines at all', function () {
        [$order] = deliveredOrder();

        expect(fn () => $this->action->handle($order, $this->customer, returnRequest([])))
            ->toThrow(ValidationException::class, 'minimal satu barang');
    });
});

describe('units a live request has committed', function () {
    it('refuses a second request for units already asked for', function () {
        [$order, $item] = deliveredOrder(quantity: 2);

        $this->action->handle(
            $order,
            $this->customer,
            returnRequest([['order_item_id' => $item->id, 'quantity' => 2]]),
        );

        expect(fn () => $this->action->handle(
            $order->refresh(),
            $this->customer,
            returnRequest([['order_item_id' => $item->id, 'quantity' => 1]]),
        ))->toThrow(ValidationException::class, 'sudah seluruhnya diajukan retur');
    });

    it('allows a second request for the units the first one left', function () {
        [$order, $item] = deliveredOrder(quantity: 3);

        $this->action->handle(
            $order,
            $this->customer,
            returnRequest([['order_item_id' => $item->id, 'quantity' => 1]]),
        );

        $second = $this->action->handle(
            $order->refresh(),
            $this->customer,
            returnRequest([['order_item_id' => $item->id, 'quantity' => 2]]),
        );

        expect($second->items->first()->quantity)->toBe(2)
            ->and(OrderReturn::withoutGlobalScopes()->count())->toBe(2);
    });

    it('releases the units again when a request is rejected', function () {
        [$order, $item] = deliveredOrder(quantity: 2);
        $staff = User::factory()->adminCabang($this->branch)->create();

        $first = $this->action->handle(
            $order,
            $this->customer,
            returnRequest([['order_item_id' => $item->id, 'quantity' => 2]]),
        );

        app(RejectReturn::class)->handle($first, 'Foto tidak jelas.', $staff);

        $second = $this->action->handle(
            $order->refresh(),
            $this->customer,
            returnRequest([['order_item_id' => $item->id, 'quantity' => 2]]),
        );

        expect($second->items->first()->quantity)->toBe(2);
    });

    it('releases the units again when the customer withdraws', function () {
        [$order, $item] = deliveredOrder(quantity: 2);

        $first = $this->action->handle(
            $order,
            $this->customer,
            returnRequest([['order_item_id' => $item->id, 'quantity' => 2]]),
        );

        app(CancelReturn::class)->handle($first, $this->customer);

        expect($first->fresh()->status)->toBe(ReturnStatus::Cancelled);

        $second = $this->action->handle(
            $order->refresh(),
            $this->customer,
            returnRequest([['order_item_id' => $item->id, 'quantity' => 2]]),
        );

        expect($second->items->first()->quantity)->toBe(2);
    });

    it('refuses a withdrawal once the branch has agreed to take the goods', function () {
        [$order, $item] = deliveredOrder();
        $staff = User::factory()->adminCabang($this->branch)->create();

        $return = $this->action->handle(
            $order,
            $this->customer,
            returnRequest([['order_item_id' => $item->id, 'quantity' => 1]]),
        );

        app(ApproveReturn::class)->handle($return, $staff);

        expect(fn () => app(CancelReturn::class)->handle($return->fresh(), $this->customer))
            ->toThrow(ValidationException::class, 'tidak dapat dibatalkan sendiri');
    });
});
