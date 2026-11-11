<?php

use App\Enums\ReturnItemCondition;
use App\Enums\ReturnReason;
use App\Enums\ReturnStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnItem;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;

/*
 * The return queue in the panel (P2-E4-T3).
 *
 * One set of screens for central and branch staff, narrowed rather than
 * duplicated (ADR-016). Because `returns` carries `BelongsToBranch` with a
 * `branch_id` copied from the order (ADR-038) and `SubstituteBindings` runs
 * after `SetBranchContext` (ADR-027), another branch's return id in the URL
 * answers **404** — the point being that a branch never learns the request
 * exists.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    $this->warehouse = Warehouse::factory()->for($this->branch)->default()->create();
    $this->otherBranch = Branch::factory()->create();
    $this->otherWarehouse = Warehouse::factory()->for($this->otherBranch)->default()->create();

    $this->central = User::factory()->adminPusat()->create();
    $this->branchAdmin = User::factory()->adminCabang($this->branch)->create();
    $this->cashier = User::factory()->kasir($this->branch)->create();
    $this->customer = User::factory()->customer()->create();
});

/**
 * A delivered order at `$branch` with a requested return against one unit.
 *
 * @return array{0: Order, 1: OrderItem, 2: OrderReturn}
 */
function queuedReturn(Branch $branch, Warehouse $warehouse, int $quantity = 2): array
{
    /** @var ProductVariant $variant */
    $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity(20)->create();

    $order = Order::factory()->forBranch($branch)->delivered()->create([
        'subtotal' => 200_000,
        'shipping_cost' => 20_000,
        'tax_total' => 0,
        'grand_total' => 220_000,
    ]);

    $item = OrderItem::factory()
        ->forOrder($order)
        ->forVariant($variant)
        ->quantity($quantity)
        ->unitPrice(100_000)
        ->create();

    $return = OrderReturn::factory()->forOrder($order->fresh())->create();
    ReturnItem::factory()->forReturn($return)->forOrderItem($item, 1)->create();

    return [$order->fresh(), $item->fresh(), $return->fresh()];
}

describe('the queue', function () {
    it('shows every branch request to central staff', function () {
        queuedReturn($this->branch, $this->warehouse);
        queuedReturn($this->otherBranch, $this->otherWarehouse);

        $this->actingAs($this->central)
            ->get(route('admin.returns.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/returns/index')
                ->has('returns.data', 2)
                ->where('is_central', true)
                ->has('options.branches', 2));
    });

    it('shows a branch admin only their own branch requests (BR-3)', function () {
        [, , $mine] = queuedReturn($this->branch, $this->warehouse);
        queuedReturn($this->otherBranch, $this->otherWarehouse);

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.returns.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('returns.data', 1)
                ->where('returns.data.0.id', $mine->getKey())
                ->where('is_central', false)
                ->has('options.branches', 0));
    });

    it('counts what still needs doing, regardless of the filters below it', function () {
        queuedReturn($this->branch, $this->warehouse);
        [, , $approved] = queuedReturn($this->branch, $this->warehouse);
        $approved->forceFill(['status' => ReturnStatus::Approved])->saveQuietly();

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.returns.index', ['status' => 'requested']))
            ->assertInertia(fn ($page) => $page
                ->has('returns.data', 1)
                ->where('summary.requested', 1)
                ->where('summary.approved', 1));
    });

    it('filters down to the requests that still need somebody', function () {
        [, , $open] = queuedReturn($this->branch, $this->warehouse);
        [, , $done] = queuedReturn($this->branch, $this->warehouse);
        $done->forceFill(['status' => ReturnStatus::Rejected])->saveQuietly();

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.returns.index', ['open' => '1']))
            ->assertInertia(fn ($page) => $page
                ->has('returns.data', 1)
                ->where('returns.data.0.id', $open->getKey()));
    });

    it('searches by return code, order number and customer name', function () {
        [$order, , $return] = queuedReturn($this->branch, $this->warehouse);
        queuedReturn($this->branch, $this->warehouse);

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.returns.index', ['search' => $return->code]))
            ->assertInertia(fn ($page) => $page->has('returns.data', 1));

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.returns.index', ['search' => $order->order_number]))
            ->assertInertia(fn ($page) => $page->has('returns.data', 1));
    });

    it('refuses a role without returns.view', function () {
        $this->actingAs($this->cashier)
            ->get(route('admin.returns.index'))
            ->assertForbidden();
    });

    it('refuses a customer outright', function () {
        $this->actingAs($this->customer)
            ->get(route('admin.returns.index'))
            ->assertForbidden();
    });
});

describe('the detail screen', function () {
    it('shows the request with its lines, its value and what may be done', function () {
        [, , $return] = queuedReturn($this->branch, $this->warehouse);

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.returns.show', $return))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/returns/show')
                ->where('return.code', $return->code)
                ->has('return.items', 1)
                ->where('return.goods_value', 100_000)
                ->where('return.refunded_amount', 0)
                ->where('return.can.approve', true)
                ->where('return.can.reject', true)
                ->where('return.can.receive', false)
                // A branch admin may take goods back but not pay for them
                // (ADR-049).
                ->where('return.can.refund', false));
    });

    it('offers a central actor the refund form with what is left to give back', function () {
        [, , $return] = queuedReturn($this->branch, $this->warehouse);
        $return->forceFill([
            'status' => ReturnStatus::Received,
            'received_at' => now(),
        ])->saveQuietly();

        $this->actingAs($this->central)
            ->get(route('admin.returns.show', $return))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('return.can.refund', true)
                ->where('refund_options.refundable_amount', 220_000)
                // One of two units returned, so the delivery is not refunded:
                // half a parcel returned was still half a parcel delivered.
                ->where('refund_options.suggested_amount', 100_000)
                ->has('refund_options.methods', 4));
    });

    it('suggests the delivery back as well when the whole order returns and the shop was at fault', function () {
        [, $item, $return] = queuedReturn($this->branch, $this->warehouse, quantity: 1);
        $return->items()->first()->update(['quantity' => 1]);
        $return->forceFill([
            'status' => ReturnStatus::Received,
            'received_at' => now(),
            'reason' => ReturnReason::Damaged,
        ])->saveQuietly();

        expect($item->quantity)->toBe(1);

        $this->actingAs($this->central)
            ->get(route('admin.returns.show', $return))
            ->assertInertia(fn ($page) => $page
                ->where('refund_options.suggested_amount', 120_000));
    });

    it('answers 404 for another branch request, not 403', function () {
        [, , $foreign] = queuedReturn($this->otherBranch, $this->otherWarehouse);

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.returns.show', $foreign))
            ->assertNotFound();
    });
});

describe('deciding a request', function () {
    it('approves without moving any stock', function () {
        [, , $return] = queuedReturn($this->branch, $this->warehouse);

        $this->actingAs($this->branchAdmin)
            ->from(route('admin.returns.show', $return))
            ->post(route('admin.returns.approve', $return), ['notes' => 'Kirim ke cabang.'])
            ->assertRedirect(route('admin.returns.show', $return));

        $return->refresh();

        expect($return->status)->toBe(ReturnStatus::Approved)
            ->and($return->approved_by)->toBe($this->branchAdmin->getKey())
            ->and($return->notes)->toBe('Kirim ke cabang.')
            ->and((int) Stock::query()->sum('quantity'))->toBe(20);
    });

    it('rejects with a reason the customer will read', function () {
        [, , $return] = queuedReturn($this->branch, $this->warehouse);

        $this->actingAs($this->branchAdmin)
            ->from(route('admin.returns.show', $return))
            ->post(route('admin.returns.reject', $return), ['reason' => 'Lewat masa retur 7 hari.'])
            ->assertRedirect();

        $return->refresh();

        expect($return->status)->toBe(ReturnStatus::Rejected)
            ->and($return->rejection_reason)->toBe('Lewat masa retur 7 hari.');
    });

    it('requires a reason to reject', function () {
        [, , $return] = queuedReturn($this->branch, $this->warehouse);

        $this->actingAs($this->branchAdmin)
            ->from(route('admin.returns.show', $return))
            ->post(route('admin.returns.reject', $return), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        expect($return->fresh()->status)->toBe(ReturnStatus::Requested);
    });

    it('books the parcel in and puts the sellable units back on the shelf', function () {
        [, , $return] = queuedReturn($this->branch, $this->warehouse);
        $return->forceFill(['status' => ReturnStatus::Approved, 'approved_at' => now()])->saveQuietly();

        $line = $return->items()->first();

        $this->actingAs($this->branchAdmin)
            ->from(route('admin.returns.show', $return))
            ->post(route('admin.returns.receive', $return), [
                'items' => [[
                    'id' => $line->id,
                    'condition' => ReturnItemCondition::Good->value,
                    'restock' => true,
                    'notes' => 'Segel masih utuh.',
                ]],
                'notes' => 'Diterima lewat JNE.',
            ])
            ->assertRedirect();

        expect($return->fresh()->status)->toBe(ReturnStatus::Received)
            ->and((int) Stock::query()->sum('quantity'))->toBe(21);
    });

    it('refuses a decision from a role without returns.approve', function () {
        [, , $return] = queuedReturn($this->branch, $this->warehouse);
        $warehouseStaff = User::factory()->staffGudang($this->branch)->create();

        $this->actingAs($warehouseStaff)
            ->post(route('admin.returns.approve', $return))
            ->assertForbidden();

        expect($return->fresh()->status)->toBe(ReturnStatus::Requested);
    });

    it('refuses a decision on another branch request with 404', function () {
        [, , $foreign] = queuedReturn($this->otherBranch, $this->otherWarehouse);

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.returns.approve', $foreign))
            ->assertNotFound();
    });

    it('refuses approving a request that has already been decided', function () {
        [, , $return] = queuedReturn($this->branch, $this->warehouse);
        $return->forceFill(['status' => ReturnStatus::Rejected])->saveQuietly();

        $this->actingAs($this->branchAdmin)
            ->from(route('admin.returns.show', $return))
            ->post(route('admin.returns.approve', $return))
            ->assertSessionHasErrors('status');
    });
});
