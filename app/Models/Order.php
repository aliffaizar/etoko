<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\BelongsToBranch;
use App\Enums\FulfillmentType;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Carbon\CarbonInterface;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A customer order (P1-E7-T1).
 *
 * Branch-scoped like every other transactional table (BR-3): branch staff see
 * their branch's orders and nobody else's, and because `SubstituteBindings`
 * runs after `SetBranchContext` (ADR-027) another branch's order number in the
 * URL answers 404 rather than 403.
 *
 * Status is only ever changed by `App\Actions\Order\TransitionOrderStatus`,
 * which checks the move against `OrderStatus::allowedTransitions()`, writes the
 * `order_status_histories` row, stamps the matching timestamp, and releases or
 * commits the stock the order is holding. Writing `status` directly skips all
 * four.
 *
 * Every descriptive and monetary column is a snapshot of checkout, not a view
 * onto current master data (ADR-007).
 *
 * @property int $id
 * @property string $order_number
 * @property int|null $user_id
 * @property int $branch_id
 * @property OrderChannel $channel
 * @property FulfillmentType $fulfillment_type
 * @property OrderStatus $status
 * @property PaymentStatus $payment_status
 * @property string $customer_name
 * @property string|null $customer_email
 * @property string|null $customer_phone
 * @property string|null $shipping_recipient
 * @property string|null $shipping_phone
 * @property string|null $shipping_address_line
 * @property string|null $shipping_district_name
 * @property string|null $shipping_city_name
 * @property string|null $shipping_province_name
 * @property string|null $shipping_postal_code
 * @property string|null $shipping_latitude
 * @property string|null $shipping_longitude
 * @property int $subtotal
 * @property int $discount_total
 * @property int $shipping_cost
 * @property int $tax_total
 * @property int $grand_total
 * @property int $total_weight_gram
 * @property int|null $voucher_id
 * @property string|null $voucher_code
 * @property string|null $pickup_code
 * @property string|null $customer_note
 * @property string|null $internal_note
 * @property Carbon|null $placed_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $processed_at
 * @property Carbon|null $ready_at
 * @property Carbon|null $shipped_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Branch $branch
 * @property-read User|null $user
 * @property-read User|null $createdBy
 * @property-read Collection<int, OrderItem> $items
 * @property-read Collection<int, OrderStatusHistory> $statusHistories
 * @property-read Collection<int, StockReservation> $stockReservations
 * @property-read Collection<int, Payment> $payments
 * @property-read Collection<int, Shipment> $shipments
 * @property-read Collection<int, OrderReturn> $returns
 * @property-read Collection<int, Refund> $refunds
 * @property-read Invoice|null $invoice
 * @property-read Voucher|null $voucher
 * @property-read VoucherUsage|null $voucherUsage
 */
#[Fillable([
    'order_number', 'user_id', 'branch_id', 'channel', 'fulfillment_type',
    'status', 'payment_status',
    'customer_name', 'customer_email', 'customer_phone',
    'shipping_recipient', 'shipping_phone', 'shipping_address_line',
    'shipping_district_name', 'shipping_city_name', 'shipping_province_name',
    'shipping_postal_code', 'shipping_latitude', 'shipping_longitude',
    'subtotal', 'discount_total', 'shipping_cost', 'tax_total', 'grand_total',
    'total_weight_gram', 'voucher_id', 'voucher_code', 'pickup_code',
    'customer_note', 'internal_note',
    'placed_at', 'paid_at', 'processed_at', 'ready_at', 'shipped_at', 'completed_at',
    'cancelled_at', 'cancel_reason', 'created_by',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use Auditable, BelongsToBranch, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => OrderChannel::class,
            'fulfillment_type' => FulfillmentType::class,
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'subtotal' => 'integer',
            'discount_total' => 'integer',
            'shipping_cost' => 'integer',
            'tax_total' => 'integer',
            'grand_total' => 'integer',
            'total_weight_gram' => 'integer',
            'placed_at' => 'datetime',
            'paid_at' => 'datetime',
            'processed_at' => 'datetime',
            'ready_at' => 'datetime',
            'shipped_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * The order's status timeline, oldest first — the sequence it happened in.
     *
     * @return HasMany<OrderStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    /**
     * @return HasMany<StockReservation, $this>
     */
    public function stockReservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    /**
     * Every attempt to collect the money for this order, newest first
     * (P2-E1-T1).
     *
     * Newest first because that is the one every screen is about: the attempt
     * in progress, or the one that just failed. The older rows are history, and
     * keeping them is what makes "we asked for this money twice" visible.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByDesc('id');
    }

    /**
     * Every dispatch of this order's goods, newest first (P2-E2-T1).
     *
     * Newest first because that is the one every screen is about: the parcel
     * on its way, or the one that just came back. The older rows are history,
     * and keeping them is what makes "this was sent twice" visible.
     *
     * @return HasMany<Shipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class)->orderByDesc('id');
    }

    /**
     * Every request to send this order's goods back, newest first (P2-E4-T1).
     *
     * Several are normal rather than exceptional: a customer who sends one
     * item back in March and another in May has two returns, and
     * `order_items.refunded_quantity` is what keeps the two from together
     * exceeding what was bought.
     *
     * @return HasMany<OrderReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(OrderReturn::class)->orderByDesc('id');
    }

    /**
     * Every movement of money back to the customer, newest first (P2-E4-T4).
     *
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderByDesc('id');
    }

    /**
     * The invoice the order was given when it was paid for (P2-E6-T1).
     *
     * @return HasOne<Invoice, $this>
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    /**
     * The voucher redeemed on this order, while it still exists
     * (`voucher_code` is the snapshot that outlives it) (P2-E5-T4).
     *
     * @return BelongsTo<Voucher, $this>
     */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /**
     * @return HasOne<VoucherUsage, $this>
     */
    public function voucherUsage(): HasOne
    {
        return $this->hasOne(VoucherUsage::class);
    }

    /**
     * Why this order has no invoice to issue, or null when it has one due.
     *
     * An invoice is owed from the moment the order is paid for, and stays
     * owed after that — a cancelled or refunded order was still sold, and the
     * refund is a document of its own. `paid_at` is the test rather than the
     * status, because it is stamped once on the way through `paid` and every
     * later status keeps it. A COD order passes too: it reaches `paid` when
     * its goods are committed (ADR-040), and its invoice says the money is
     * still to be collected.
     */
    public function invoiceBlockedReason(): ?string
    {
        if ($this->paid_at === null) {
            return 'Invoice terbit setelah pesanan dibayar.';
        }

        return null;
    }

    /**
     * How much of this order's money has actually gone back (P2-E4-T4, T5).
     *
     * Only `processed` refunds count: a reversal still in flight is money the
     * shop has promised, not money the customer has, and treating it as
     * returned would let the next refund exceed what was ever paid.
     *
     * **The order's own totals are never touched by a refund** — `subtotal`,
     * `grand_total` and every line's `subtotal` stay as they were sold
     * (ADR-047). What a refund changes is `payment_status`, the lines'
     * `refunded_quantity`, and this sum. That is what keeps a partial refund
     * from quietly rewriting last month's sales figures.
     */
    public function refundedAmount(): int
    {
        if ($this->relationLoaded('refunds')) {
            return (int) $this->refunds
                ->filter(fn (Refund $refund): bool => $refund->status->isSettled())
                ->sum('amount');
        }

        return (int) $this->refunds()->settled()->sum('amount');
    }

    /**
     * How much of this order could still be sent back.
     *
     * The ceiling is what the customer actually paid — `grand_total`, not the
     * goods' value — because the delivery they paid for is refundable too when
     * the shop was at fault (`ReturnReason::faultOfShop()`). Method fees are
     * **not** in it: `payments.fee_amount` is the gateway's charge for taking
     * the money, the gateway keeps it on a reversal, and offering it back
     * would be the shop paying a fee twice (ADR-039).
     */
    public function refundableAmount(): int
    {
        return max($this->grand_total - $this->refundedAmount(), 0);
    }

    /**
     * Why this order's goods may not be sent back, or null when they may be
     * (P2-E4-T2).
     *
     * One source for `RequestReturn`'s refusal and for the `can.request_return`
     * flag the storefront reads, so the button is never offered for an order
     * the server would turn down.
     *
     * Goods can only come back once they have **gone** — `delivered` or
     * `completed`, which is exactly the pair the state machine lets move on to
     * `refunded` (docs/03-data-model.md §6). An order still being packed is
     * cancelled, not returned, and the two are different documents with
     * different consequences for stock.
     */
    public function returnBlockedReason(): ?string
    {
        if (! in_array($this->status, [OrderStatus::Delivered, OrderStatus::Completed], strict: true)) {
            return "Pesanan berstatus \"{$this->status->label()}\" belum dapat diretur. "
                .'Retur dibuka setelah barang diterima pelanggan.';
        }

        if (! in_array($this->payment_status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], strict: true)) {
            return "Pesanan dengan pembayaran \"{$this->payment_status->label()}\" tidak dapat diretur.";
        }

        return null;
    }

    /**
     * Whether the customer may still ask to send goods back.
     */
    public function isReturnable(): bool
    {
        return $this->returnBlockedReason() === null;
    }

    /**
     * The dispatch still in play, or null when there is none.
     *
     * At most one can exist: `CreateShipment` refuses to open a second while
     * one is still moving, so an order never has two parcels nobody can tell
     * apart. A returned parcel leaves none, which is exactly what makes
     * re-sending it possible.
     *
     * Reads a loaded `shipments` relation when the caller has one, so a
     * listing costs no query per row.
     */
    public function activeShipment(): ?Shipment
    {
        if ($this->relationLoaded('shipments')) {
            return $this->shipments->first(fn (Shipment $shipment): bool => $shipment->isOpen());
        }

        return $this->shipments()->open()->first();
    }

    /**
     * Whether this order's goods are collected at the branch (P2-E2-T7).
     */
    public function isPickup(): bool
    {
        return $this->fulfillment_type === FulfillmentType::Pickup;
    }

    /**
     * Whether this order needs a courier at all.
     */
    public function requiresShipping(): bool
    {
        return $this->fulfillment_type->requiresShipping();
    }

    /**
     * Why this order's goods may not be handed to a courier, or null when they
     * may be (P2-E2-T4).
     *
     * One source for `DispatchShipment`'s refusal and for the `can.dispatch`
     * flag the panel reads, so a button is never offered for something the
     * server will turn down.
     *
     * The order has to be *being worked on*, not merely paid for: an order
     * still in `paid` has not been picked, and a waybill against unpicked
     * goods is a parcel somebody has to go and find.
     */
    public function dispatchBlockedReason(): ?string
    {
        if (! $this->requiresShipping()) {
            return 'Pesanan ini diambil sendiri di cabang, jadi tidak ada pengiriman untuk dicatat.';
        }

        if ($this->status === OrderStatus::Shipped) {
            return 'Pesanan ini sudah dikirim. Perbarui statusnya dari kartu pengiriman.';
        }

        if ($this->status !== OrderStatus::Processing) {
            return "Pesanan berstatus \"{$this->status->label()}\" belum siap dikirim. "
                .'Pindahkan ke "Diproses" terlebih dahulu.';
        }

        return null;
    }

    /**
     * Why this order may not be marked as collected, or null when it may be
     * (P2-E2-T7).
     */
    public function pickupConfirmationBlockedReason(): ?string
    {
        if (! $this->isPickup()) {
            return 'Pesanan ini dikirim ke alamat pelanggan, bukan diambil di cabang.';
        }

        if ($this->status !== OrderStatus::ReadyForPickup) {
            return "Pesanan berstatus \"{$this->status->label()}\" belum menunggu diambil.";
        }

        return null;
    }

    /**
     * Whether `$code` is the code issued for this order (P2-E2-T7).
     *
     * Compared case-insensitively and trimmed, because it is read off a phone
     * screen and typed in by somebody at a counter. Compared with
     * `hash_equals` because it is the one thing standing between a stranger
     * and somebody else's parcel, and a short-circuiting comparison on a
     * ten-character secret is a timing oracle for exactly that.
     *
     * An order with no code refuses every code rather than accepting any: a
     * null `pickup_code` means the goods are not waiting yet.
     */
    public function matchesPickupCode(string $code): bool
    {
        if ($this->pickup_code === null) {
            return false;
        }

        return hash_equals(
            mb_strtoupper($this->pickup_code),
            mb_strtoupper(trim($code)),
        );
    }

    /**
     * A fresh collection code.
     *
     * Six characters from an alphabet with no `0`/`O` or `1`/`I` in it,
     * because it is read aloud over a counter and those are the pairs people
     * get wrong. Not unique across the table and deliberately not treated as
     * an identifier — `ConfirmOrderPickup` checks it against *this* order,
     * which is what keeps six characters enough.
     */
    public static function newPickupCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($index = 0; $index < 6; $index++) {
            $code .= $alphabet[random_int(0, mb_strlen($alphabet) - 1)];
        }

        return $code;
    }

    /**
     * The attempt still waiting for money, or null when there is none.
     *
     * At most one can exist: `InitiatePayment` closes any open attempt before
     * opening another, so a customer who switches method does not leave two
     * live asks for the same order.
     *
     * Reads a loaded `payments` relation when the caller has one, so a listing
     * costs no query per row.
     */
    public function openPayment(): ?Payment
    {
        if ($this->relationLoaded('payments')) {
            return $this->payments->first(fn (Payment $payment): bool => $payment->isOpen());
        }

        return $this->payments()->open()->first();
    }

    /**
     * Whether this order's money is collected by the courier (ADR-040).
     *
     * Read from the order's most recent attempt rather than from a column:
     * the method is a property of the attempt, and a customer may switch from
     * COD to a transfer before anybody has collected anything.
     *
     * This is what `TransitionOrderStatus` consults when an order reaches
     * `paid`, so a COD order is confirmed — its goods committed — while its
     * `payment_status` stays `pending` until the cash is recorded.
     */
    public function requiresCashOnDelivery(): bool
    {
        $payment = $this->relationLoaded('payments')
            ? $this->payments->first()
            : $this->payments()->latestFirst()->first();

        return $payment instanceof Payment && $payment->method_type->isCollectedOnDelivery();
    }

    /**
     * When the money for this order has to arrive, or null when nothing is
     * holding it (P2-E1-T3, ADR-041).
     *
     * **Taken from the stock holds, not configured separately.** The hold is
     * what keeps the goods off the shelf; a payment window that outlived it
     * would show a customer an order they can still pay for whose units have
     * gone back on sale, and `CommitReservation` would then have nothing to
     * commit when they did — the exact leak `HIGH-03` was. The earliest hold
     * decides, because the first one to lapse is the first line of the order
     * that can no longer be filled.
     *
     * Null for an order holding nothing: a COD order whose goods have already
     * been committed is not waiting on a clock, and neither is an order whose
     * holds have been released.
     */
    public function paymentDeadline(): ?CarbonInterface
    {
        $earliest = $this->stockReservations()
            ->active()
            ->whereNotNull('expires_at')
            ->min('expires_at');

        return $earliest === null ? null : Carbon::parse($earliest);
    }

    /**
     * Why this order cannot be paid for, or null when it can.
     *
     * One source for `InitiatePayment`'s refusal and for the `can.pay` flag
     * the storefront reads, so the pay button is never offered for an order
     * the server would turn down.
     */
    public function paymentBlockedReason(): ?string
    {
        if ($this->payment_status === PaymentStatus::Paid) {
            return 'Pesanan ini sudah lunas.';
        }

        if ($this->status !== OrderStatus::PendingPayment) {
            return "Pesanan berstatus \"{$this->status->label()}\" tidak dapat dibayar lagi.";
        }

        return null;
    }

    /**
     * Whether a customer may still start or retry a payment for this order.
     */
    public function isPayable(): bool
    {
        return $this->paymentBlockedReason() === null;
    }

    /**
     * Units on the order, across every line.
     *
     * Reuses an `items_sum_quantity` the caller already loaded with
     * `withSum('items', 'quantity')`, so a listing costs no query per row.
     */
    public function totalQuantity(): int
    {
        $summed = $this->getAttribute('items_sum_quantity');

        if ($summed !== null) {
            return (int) $summed;
        }

        return $this->relationLoaded('items')
            ? (int) $this->items->sum('quantity')
            : (int) $this->items()->sum('quantity');
    }

    /**
     * Whether the customer may still call this order off themselves.
     *
     * Deliberately narrower than what an admin may do: once a branch has begun
     * picking the order, calling it off is a conversation, not a button
     * (P1-E7-T9).
     */
    public function isCancellableByCustomer(): bool
    {
        return $this->status === OrderStatus::PendingPayment;
    }

    /**
     * Why this order may not be cancelled, or null when it may be.
     *
     * One source for the endpoint's refusal and for the `can.cancel` flag the
     * screens read, so a button is never offered for something the server will
     * refuse — the pattern `Branch::deletionBlockedReason()` set.
     */
    public function cancellationBlockedReason(bool $asCustomer): ?string
    {
        if (! $this->status->canTransitionTo(OrderStatus::Cancelled)) {
            return "Pesanan berstatus \"{$this->status->label()}\" tidak dapat dibatalkan.";
        }

        if ($asCustomer && ! $this->isCancellableByCustomer()) {
            return 'Pesanan sudah mulai diproses. Hubungi cabang untuk membatalkannya.';
        }

        return null;
    }

    /**
     * Why the processing branch may not be moved, or null when it may be.
     *
     * Moving an order means moving the stock it holds (P1-E7-T10), and stock
     * that has already left the shelf cannot be un-picked by changing a column.
     *
     * **Only `pending_payment` may be moved**, which is narrower than it used
     * to be (MED-04, ADR-036). An unpaid order holds a reservation, and
     * `AssignOrderBranch` can take that hold at the destination and give it
     * back at the origin in one transaction. A paid one holds nothing: its
     * goods have gone out of the origin's shelf as `sale_out`. Moving it used
     * to change `branch_id` and nothing else, which left the destination with
     * an order whose goods it never issued, the origin short of stock for an
     * order that is no longer its own, and not one ledger row to explain
     * either — permanently unreconcilable, against ADR-024, and against the
     * destination-stock validation `P1-E7-T10` promised.
     *
     * Moving goods between branches is a stock transfer document, which is
     * `P2-E3`. Until that exists, this says no.
     */
    public function branchReassignmentBlockedReason(): ?string
    {
        if ($this->status->isFinal()) {
            return "Pesanan berstatus \"{$this->status->label()}\" sudah selesai, cabangnya tidak dapat dipindahkan.";
        }

        if (in_array($this->status, [OrderStatus::Shipped, OrderStatus::Delivered], strict: true)) {
            return 'Pesanan sudah dikirim, jadi cabang pemrosesnya tidak dapat dipindahkan.';
        }

        if ($this->status !== OrderStatus::PendingPayment) {
            return 'Pesanan yang sudah dibayar tidak dapat dipindahkan cabang, karena barangnya sudah '
                .'keluar dari stok cabang asal. Pemindahan barang antar cabang memakai dokumen transfer stok.';
        }

        return null;
    }

    /**
     * The moment column that records an order reaching the given status.
     *
     * Returns null for statuses that have no column of their own; their record
     * is the history row, which every transition writes regardless.
     */
    public static function timestampColumnFor(OrderStatus $status): ?string
    {
        return match ($status) {
            OrderStatus::Paid => 'paid_at',
            OrderStatus::Processing => 'processed_at',
            OrderStatus::ReadyForPickup => 'ready_at',
            OrderStatus::Shipped => 'shipped_at',
            OrderStatus::Completed => 'completed_at',
            OrderStatus::Cancelled, OrderStatus::Expired => 'cancelled_at',
            default => null,
        };
    }

    /**
     * Orders still awaiting something from the branch.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function open(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(
            fn (OrderStatus $status): string => $status->value,
            OrderStatus::openStatuses(),
        ));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function latestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
