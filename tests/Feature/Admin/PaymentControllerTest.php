<?php

use App\Actions\Order\ReserveStock;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\ReservationStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;

/*
 * The payment queue in the panel (P2-E1-T4).
 *
 * One set of screens for central and branch staff, narrowed rather than
 * duplicated (ADR-016). Because `payments` carries `BelongsToBranch` with a
 * `branch_id` copied from the order (ADR-038) and `SubstituteBindings` runs
 * after `SetBranchContext` (ADR-027), another branch's payment id in the URL
 * answers **404** — the point being that a branch never learns the payment
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
 * An unpaid order at a branch, with a pending transfer against it.
 *
 * Built through `ReserveStock` so the holds the settlement will commit are
 * real ones.
 *
 * @return array{0: Order, 1: Payment}
 */
function queuedPayment(Branch $branch, Warehouse $warehouse, int $held = 2, int $onHand = 10): array
{
    /** @var ProductVariant $variant */
    $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity($onHand)->create();

    $order = Order::factory()->forBranch($branch)->create([
        'subtotal' => 500_000,
        'shipping_cost' => 0,
        'tax_total' => 0,
        'grand_total' => 500_000,
        'payment_status' => PaymentStatus::Pending,
    ]);

    OrderItem::factory()->forOrder($order)->forVariant($variant)->quantity($held)->create();
    app(ReserveStock::class)->handle($order, [['variant' => $variant, 'quantity' => $held]]);

    $payment = Payment::factory()->forOrder($order->fresh())->withProof()->create();

    return [$order->fresh(), $payment];
}

describe('the queue', function () {
    it('shows every branch payments to central staff', function () {
        queuedPayment($this->branch, $this->warehouse);
        queuedPayment($this->otherBranch, $this->otherWarehouse);

        $this->actingAs($this->central)
            ->get(route('admin.payments.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/payments/index')
                ->has('payments.data', 2)
                ->where('is_central', true)
                ->has('options.branches', 2));
    });

    it('shows a branch admin only their own branch payments (BR-3)', function () {
        [, $mine] = queuedPayment($this->branch, $this->warehouse);
        queuedPayment($this->otherBranch, $this->otherWarehouse);

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.payments.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.id', $mine->getKey())
                ->where('is_central', false)
                ->has('options.branches', 0));
    });

    it('counts the receipts waiting to be looked at, not merely the open attempts', function () {
        queuedPayment($this->branch, $this->warehouse);

        // An open attempt with no receipt: a customer who has not paid yet,
        // which is nothing for a person to do.
        $order = Order::factory()->forBranch($this->branch)->create();
        Payment::factory()->forOrder($order)->create();

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.payments.index'))
            ->assertInertia(fn ($page) => $page
                ->where('summary.awaiting_verification', 1)
                ->where('summary.open', 2));
    });

    it('filters down to the receipts waiting to be looked at', function () {
        [, $withProof] = queuedPayment($this->branch, $this->warehouse);
        Payment::factory()->forOrder(Order::factory()->forBranch($this->branch)->create())->create();

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.payments.index', ['awaiting_proof' => '1']))
            ->assertInertia(fn ($page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.id', $withProof->getKey()));
    });

    it('filters by status and by method type', function () {
        queuedPayment($this->branch, $this->warehouse);
        Payment::factory()
            ->forOrder(Order::factory()->forBranch($this->branch)->create())
            ->forMethod(PaymentMethod::factory()->cod()->create())
            ->expired()
            ->create();

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.payments.index', ['status' => 'expired']))
            ->assertInertia(fn ($page) => $page->has('payments.data', 1));

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.payments.index', ['method_type' => 'cod']))
            ->assertInertia(fn ($page) => $page->has('payments.data', 1));
    });

    it('finds a payment by the order number a customer quoted', function () {
        [$order] = queuedPayment($this->branch, $this->warehouse);
        queuedPayment($this->branch, $this->warehouse);

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.payments.index', ['search' => $order->order_number]))
            ->assertInertia(fn ($page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.order.order_number', $order->order_number));
    });

    it('refuses a crafted sort instead of putting it in the query', function () {
        $this->actingAs($this->central)
            ->get(route('admin.payments.index', ['sort' => 'amount); drop table payments;--']))
            ->assertSessionHasErrors('sort');
    });

    it('lets a cashier read the queue but offers them no decision', function () {
        queuedPayment($this->branch, $this->warehouse);

        $this->actingAs($this->cashier)
            ->get(route('admin.payments.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.settle', false));
    });

    it('turns a customer away', function () {
        $this->actingAs($this->customer)
            ->get(route('admin.payments.index'))
            ->assertForbidden();
    });
});

describe('the detail screen', function () {
    it('shows the receipt, the method and what may be done', function () {
        [, $payment] = queuedPayment($this->branch, $this->warehouse);

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.payments.show', $payment))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/payments/show')
                ->where('payment.id', $payment->getKey())
                ->where('payment.can.settle', true)
                ->where('blocked.settle', null)
                ->whereNot('payment.proof_url', null)
                ->whereNot('payment.bank_account', null));
    });

    it('answers 404 for another branch payment, not 403 (ADR-027)', function () {
        [, $theirs] = queuedPayment($this->otherBranch, $this->otherWarehouse);

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.payments.show', $theirs))
            ->assertNotFound();
    });

    it('says why a finished attempt may no longer be decided', function () {
        $payment = Payment::factory()
            ->forOrder(Order::factory()->forBranch($this->branch)->create())
            ->success()
            ->create();

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.payments.show', $payment))
            ->assertInertia(fn ($page) => $page
                ->where('payment.can.settle', false)
                ->whereNot('blocked.settle', null));
    });
});

describe('confirming money arrived', function () {
    it('settles the payment and takes the order with it', function () {
        [$order, $payment] = queuedPayment($this->branch, $this->warehouse, held: 2, onHand: 10);

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.payments.verify', $payment), ['note' => 'Cek mutasi BCA.'])
            ->assertRedirect();

        $order->refresh();

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Success)
            ->and($payment->fresh()->verified_by)->toBe($this->branchAdmin->getKey())
            ->and($order->status)->toBe(OrderStatus::Paid)
            ->and($order->payment_status)->toBe(PaymentStatus::Paid)
            ->and(StockReservation::acrossBranches()->sole()->status)
            ->toBe(ReservationStatus::Committed)
            ->and(StockMovement::acrossBranches()->where('type', 'sale_out')->count())->toBe(1);
    });

    it('refuses a cashier, who may read but not decide', function () {
        [, $payment] = queuedPayment($this->branch, $this->warehouse);

        $this->actingAs($this->cashier)
            ->post(route('admin.payments.verify', $payment))
            ->assertForbidden();

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending);
    });

    it('answers 404 for another branch payment', function () {
        [, $theirs] = queuedPayment($this->otherBranch, $this->otherWarehouse);

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.payments.verify', $theirs))
            ->assertNotFound();
    });

    it('explains a second press instead of settling twice', function () {
        [, $payment] = queuedPayment($this->branch, $this->warehouse);

        $this->actingAs($this->branchAdmin)->post(route('admin.payments.verify', $payment));

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.payments.verify', $payment->fresh()))
            // Already settled, so the policy itself now refuses it.
            ->assertForbidden();

        expect(StockMovement::acrossBranches()->where('type', 'sale_out')->count())->toBe(1);
    });

    it('says plainly when the shelf can no longer cover the order', function () {
        [$order, $payment] = queuedPayment($this->branch, $this->warehouse, held: 2, onHand: 10);

        // The goods were issued out while the order waited, so the hold now
        // outstrips the shelf.
        Stock::acrossBranches()->sole()->update(['quantity' => 1]);

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.payments.verify', $payment))
            ->assertSessionHasErrors('payment');

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending)
            ->and($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    });
});

describe('turning a receipt down', function () {
    it('closes the attempt and leaves the order payable', function () {
        [$order, $payment] = queuedPayment($this->branch, $this->warehouse);

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.payments.reject', $payment), [
                'reason' => 'Nominal transfer kurang Rp5.000 dari total pesanan.',
            ])
            ->assertRedirect();

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Failed)
            ->and($payment->fresh()->failure_reason)->toContain('kurang Rp5.000')
            ->and($order->fresh()->isPayable())->toBeTrue()
            ->and(StockReservation::acrossBranches()->sole()->status)
            ->toBe(ReservationStatus::Active);
    });

    it('demands a reason, because the customer reads it', function () {
        [, $payment] = queuedPayment($this->branch, $this->warehouse);

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.payments.reject', $payment), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending);
    });

    it('refuses a cashier', function () {
        [, $payment] = queuedPayment($this->branch, $this->warehouse);

        $this->actingAs($this->cashier)
            ->post(route('admin.payments.reject', $payment), ['reason' => 'Tidak sesuai.'])
            ->assertForbidden();
    });
});

describe('clearing a stale attempt', function () {
    it('marks it expired', function () {
        [$order, $payment] = queuedPayment($this->branch, $this->warehouse);

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.payments.expire', $payment))
            ->assertRedirect();

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Expired)
            ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Expired);
    });

    it('refuses a cashier', function () {
        [, $payment] = queuedPayment($this->branch, $this->warehouse);

        $this->actingAs($this->cashier)
            ->post(route('admin.payments.expire', $payment))
            ->assertForbidden();
    });
});

describe('the order detail screen', function () {
    it('shows the order payment attempts to somebody who may see them', function () {
        [$order, $payment] = queuedPayment($this->branch, $this->warehouse);

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('payments', 1)
                ->where('payments.0.id', $payment->getKey())
                ->where('payments.0.can.settle', true));
    });
});
