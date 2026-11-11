<?php

use App\Enums\ReturnReason;
use App\Enums\ReturnStatus;
use App\Enums\ReturnType;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Refund;
use App\Models\ReturnItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * A customer asking to send goods back (P2-E4-T2).
 *
 * Scoped by `user_id`, not by policy — the same arrangement as
 * `Shop\OrderController` — so another customer's order is **not found** rather
 * than refused, and nothing leaks about it existing.
 */

beforeEach(function () {
    Storage::fake('public');

    $this->branch = Branch::factory()->default()->create();
    Warehouse::factory()->for($this->branch)->default()->create();
    $this->customer = User::factory()->customer()->create();
    $this->stranger = User::factory()->customer()->create();
});

/**
 * A delivered order belonging to `$owner`, with one line of two units.
 *
 * @return array{0: Order, 1: OrderItem}
 */
function deliveredCustomerOrder(User $owner): array
{
    $order = Order::factory()
        ->forBranch(test()->branch)
        ->forCustomer($owner)
        ->delivered()
        ->create([
            'subtotal' => 200_000,
            'shipping_cost' => 20_000,
            'tax_total' => 0,
            'grand_total' => 220_000,
        ]);

    $item = OrderItem::factory()
        ->forOrder($order)
        ->quantity(2)
        ->unitPrice(100_000)
        ->create();

    return [$order->fresh(), $item->fresh()];
}

describe('the request form', function () {
    it('lists every line with how many may still be sent back', function () {
        [$order, $item] = deliveredCustomerOrder($this->customer);

        $this->actingAs($this->customer)
            ->get(route('shop.account.orders.returns.create', $order))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('shop/return')
                ->where('blocked_reason', null)
                ->has('lines', 1)
                ->where('lines.0.id', $item->getKey())
                ->where('lines.0.returnable_quantity', 2)
                ->where('lines.0.returned_quantity', 0)
                ->has('options.reasons', 6)
                ->where('max_photos', OrderReturn::MAX_PHOTOS));
    });

    it('counts a live request against what may still be sent back', function () {
        [$order, $item] = deliveredCustomerOrder($this->customer);

        $return = OrderReturn::factory()->forOrder($order)->create();
        ReturnItem::factory()->forReturn($return)->forOrderItem($item, 1)->create();

        $this->actingAs($this->customer)
            ->get(route('shop.account.orders.returns.create', $order))
            ->assertInertia(fn ($page) => $page
                ->where('lines.0.returned_quantity', 1)
                ->where('lines.0.returnable_quantity', 1));
    });

    it('says why the form is closed rather than hiding it', function () {
        $order = Order::factory()
            ->forBranch($this->branch)
            ->forCustomer($this->customer)
            ->processing()
            ->create();

        OrderItem::factory()->forOrder($order)->quantity(1)->create();

        $this->actingAs($this->customer)
            ->get(route('shop.account.orders.returns.create', $order->fresh()))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('blocked_reason', fn (?string $reason) => str_contains((string) $reason, 'belum dapat diretur'))
                ->has('lines', 0));
    });

    it('answers 404 for somebody else order', function () {
        [$order] = deliveredCustomerOrder($this->stranger);

        $this->actingAs($this->customer)
            ->get(route('shop.account.orders.returns.create', $order))
            ->assertNotFound();
    });

    it('sends a guest to the login screen', function () {
        [$order] = deliveredCustomerOrder($this->customer);

        $this->get(route('shop.account.orders.returns.create', $order))
            ->assertRedirect(route('login'));
    });
});

describe('submitting a request', function () {
    it('opens a numbered request and lands back on the order', function () {
        [$order, $item] = deliveredCustomerOrder($this->customer);

        $this->actingAs($this->customer)
            ->post(route('shop.account.orders.returns.store', $order), [
                'type' => ReturnType::Refund->value,
                'reason' => ReturnReason::WrongItem->value,
                'description' => 'Ukurannya bukan yang saya pesan.',
                'items' => [
                    ['order_item_id' => $item->id, 'quantity' => 1],
                ],
            ])
            ->assertRedirect(route('shop.account.orders.show', $order));

        $return = OrderReturn::withoutGlobalScopes()->sole();

        expect($return->code)->toStartWith('RTN-')
            ->and($return->status)->toBe(ReturnStatus::Requested)
            ->and($return->user_id)->toBe($this->customer->getKey())
            ->and($return->branch_id)->toBe($this->branch->getKey())
            ->and($return->items)->toHaveCount(1)
            ->and($return->items->first()->quantity)->toBe(1);
    });

    it('drops the lines the customer left at zero', function () {
        [$order, $item] = deliveredCustomerOrder($this->customer);
        $second = OrderItem::factory()->forOrder($order)->quantity(1)->unitPrice(50_000)->create();

        $this->actingAs($this->customer)
            ->post(route('shop.account.orders.returns.store', $order), [
                'type' => ReturnType::Refund->value,
                'reason' => ReturnReason::Damaged->value,
                'items' => [
                    ['order_item_id' => $item->id, 'quantity' => 2],
                    ['order_item_id' => $second->id, 'quantity' => 0],
                ],
            ])
            ->assertRedirect();

        $return = OrderReturn::withoutGlobalScopes()->sole();

        expect($return->items)->toHaveCount(1)
            ->and($return->items->first()->order_item_id)->toBe($item->id);
    });

    it('stores the photos and keeps their paths on the request', function () {
        [$order, $item] = deliveredCustomerOrder($this->customer);

        $this->actingAs($this->customer)
            ->post(route('shop.account.orders.returns.store', $order), [
                'type' => ReturnType::Refund->value,
                'reason' => ReturnReason::Damaged->value,
                'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
                'photos' => [
                    UploadedFile::fake()->image('rusak-1.jpg'),
                    UploadedFile::fake()->image('rusak-2.jpg'),
                ],
            ])
            ->assertRedirect();

        $return = OrderReturn::withoutGlobalScopes()->sole();

        expect($return->photo_paths)->toHaveCount(2);

        foreach ($return->photo_paths as $path) {
            Storage::disk('public')->assertExists($path);
        }
    });

    it('does not leave orphaned photos behind when the request is refused', function () {
        [$order, $item] = deliveredCustomerOrder($this->customer);

        $this->actingAs($this->customer)
            ->post(route('shop.account.orders.returns.store', $order), [
                'type' => ReturnType::Refund->value,
                'reason' => ReturnReason::Damaged->value,
                // More than were bought: refused by `RequestReturn`.
                'items' => [['order_item_id' => $item->id, 'quantity' => 5]],
                'photos' => [UploadedFile::fake()->image('rusak.jpg')],
            ])
            ->assertSessionHasErrors();

        expect(OrderReturn::withoutGlobalScopes()->count())->toBe(0)
            ->and(Storage::disk('public')->allFiles(OrderReturn::PHOTO_DIRECTORY))->toBe([]);
    });

    it('requires an explanation when the reason is "other"', function () {
        [$order, $item] = deliveredCustomerOrder($this->customer);

        $this->actingAs($this->customer)
            ->post(route('shop.account.orders.returns.store', $order), [
                'type' => ReturnType::Refund->value,
                'reason' => ReturnReason::Other->value,
                'description' => '',
                'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertSessionHasErrors('description');

        expect(OrderReturn::withoutGlobalScopes()->count())->toBe(0);
    });

    it('refuses a reason the enum does not know', function () {
        [$order, $item] = deliveredCustomerOrder($this->customer);

        $this->actingAs($this->customer)
            ->post(route('shop.account.orders.returns.store', $order), [
                'type' => ReturnType::Refund->value,
                'reason' => 'karena-saja',
                'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertSessionHasErrors('reason');
    });

    it('answers 404 for somebody else order', function () {
        [$order, $item] = deliveredCustomerOrder($this->stranger);

        $this->actingAs($this->customer)
            ->post(route('shop.account.orders.returns.store', $order), [
                'type' => ReturnType::Refund->value,
                'reason' => ReturnReason::Damaged->value,
                'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertNotFound();

        expect(OrderReturn::withoutGlobalScopes()->count())->toBe(0);
    });
});

describe('withdrawing a request', function () {
    it('cancels a request nobody has acted on', function () {
        [$order, $item] = deliveredCustomerOrder($this->customer);

        $return = OrderReturn::factory()->forOrder($order)->create();
        ReturnItem::factory()->forReturn($return)->forOrderItem($item, 1)->create();

        $this->actingAs($this->customer)
            ->from(route('shop.account.orders.show', $order))
            ->post(route('shop.account.orders.returns.cancel', [$order, $return]))
            ->assertRedirect(route('shop.account.orders.show', $order));

        expect($return->fresh()->status)->toBe(ReturnStatus::Cancelled);
    });

    it('answers 404 for a return belonging to another order', function () {
        [$order] = deliveredCustomerOrder($this->customer);
        [$otherOrder, $otherItem] = deliveredCustomerOrder($this->customer);

        $foreign = OrderReturn::factory()->forOrder($otherOrder)->create();
        ReturnItem::factory()->forReturn($foreign)->forOrderItem($otherItem, 1)->create();

        $this->actingAs($this->customer)
            ->post(route('shop.account.orders.returns.cancel', [$order, $foreign]))
            ->assertNotFound();

        expect($foreign->fresh()->status)->toBe(ReturnStatus::Requested);
    });
});

describe('the order page', function () {
    it('shows the requests and the money that has come back', function () {
        [$order, $item] = deliveredCustomerOrder($this->customer);

        $return = OrderReturn::factory()->forOrder($order)->rejected('Foto tidak jelas.')->create();
        ReturnItem::factory()->forReturn($return)->forOrderItem($item, 1)->create();

        Refund::factory()->forOrder($order)->amount(50_000)->processed()->create();

        $this->actingAs($this->customer)
            ->get(route('shop.account.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('returns', 1)
                ->where('returns.0.code', $return->code)
                ->where('returns.0.rejection_reason', 'Foto tidak jelas.')
                ->where('returns.0.can_cancel', false)
                ->has('refunds', 1)
                ->where('refunds.0.amount', 50_000)
                ->where('order.can.request_return', true));
    });

    it('offers no return button while the goods are still on their way', function () {
        $order = Order::factory()
            ->forBranch($this->branch)
            ->forCustomer($this->customer)
            ->processing()
            ->create();

        OrderItem::factory()->forOrder($order)->quantity(1)->create();

        $this->actingAs($this->customer)
            ->get(route('shop.account.orders.show', $order->fresh()))
            ->assertInertia(fn ($page) => $page
                ->where('order.can.request_return', false)
                ->where('return_blocked_reason', fn (?string $reason) => $reason !== null));
    });
});
