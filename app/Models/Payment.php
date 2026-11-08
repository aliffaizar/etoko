<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\BelongsToBranch;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentTransactionStatus;
use Carbon\CarbonInterface;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One attempt to collect the money for an order (P2-E1-T1).
 *
 * Branch-scoped like every other transactional table (BR-3) through a
 * `branch_id` copied from the order (ADR-038), so another branch's payment id
 * in the URL answers 404 rather than 403 (ADR-027).
 *
 * The status of a payment is only ever changed by the actions in
 * `App\Actions\Payment`, each of which re-reads the row under its lock. Writing
 * `status` directly skips the order-level consequence — `orders.payment_status`
 * and, for a successful payment, the transition to `paid` that takes the goods
 * off the shelf.
 *
 * `method_name` and `method_type` are snapshots (ADR-007). The `paymentMethod`
 * relation is for reading the *current* arrangement — the bank account to
 * transfer into, the gateway to call — and never for describing how an old
 * payment was made.
 *
 * @property int $id
 * @property int $order_id
 * @property int $branch_id
 * @property int $payment_method_id
 * @property string $method_name
 * @property PaymentMethodType $method_type
 * @property int $amount
 * @property int $fee_amount
 * @property PaymentTransactionStatus $status
 * @property string $reference
 * @property string|null $external_id
 * @property string|null $va_number
 * @property string|null $qr_string
 * @property string|null $payment_url
 * @property string|null $proof_path
 * @property string|null $proof_note
 * @property Carbon|null $proof_uploaded_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $expires_at
 * @property string|null $failure_reason
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property array<string, mixed>|null $raw_payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order $order
 * @property-read Branch $branch
 * @property-read PaymentMethod $paymentMethod
 * @property-read User|null $verifiedBy
 */
#[Fillable([
    'order_id', 'branch_id', 'payment_method_id', 'method_name', 'method_type',
    'amount', 'fee_amount', 'status', 'reference', 'external_id',
    'va_number', 'qr_string', 'payment_url',
    'proof_path', 'proof_note', 'proof_uploaded_at',
    'paid_at', 'expires_at', 'failure_reason',
    'verified_by', 'verified_at', 'raw_payload',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use Auditable, BelongsToBranch, HasFactory;

    /** Where a customer's transfer receipts live on the disk. */
    public const PROOF_DIRECTORY = 'payment-proofs';

    /** The disk receipts are written to. */
    public const PROOF_DISK = 'public';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method_type' => PaymentMethodType::class,
            'status' => PaymentTransactionStatus::class,
            'amount' => 'integer',
            'fee_amount' => 'integer',
            'proof_uploaded_at' => 'datetime',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * A new, unused reference for a gateway to key its transaction on.
     *
     * Built from the order number so a support call about `ORD-20261108-0003`
     * can be traced in the gateway's dashboard without a lookup, and suffixed
     * with eight random characters because a gateway refuses a second
     * transaction under an id it has already seen — which is exactly what a
     * retry of a failed attempt would be.
     */
    public static function newReferenceFor(Order $order): string
    {
        return $order->order_number.'-'.mb_strtoupper(Str::random(8));
    }

    /**
     * Whether this attempt is still waiting for money.
     */
    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * Whether the deadline has passed without the money arriving.
     *
     * A payment with no deadline never overruns: a COD has nothing to wait
     * for, and a method configured without one is one the shop has decided to
     * leave open.
     */
    public function hasOverrun(?CarbonInterface $asOf = null): bool
    {
        if (! $this->isOpen() || $this->expires_at === null) {
            return false;
        }

        return $this->expires_at->lessThanOrEqualTo($asOf ?? now());
    }

    /**
     * Whether the customer may still upload a receipt for this attempt.
     */
    public function acceptsProof(): bool
    {
        return $this->isOpen()
            && $this->method_type->expectsProofOfTransfer()
            && ! $this->hasOverrun();
    }

    /**
     * Whether a staff member may still confirm the money arrived.
     *
     * An overdue attempt is **not** excluded: a transfer that landed two
     * minutes after the deadline is still the customer's money, and refusing
     * to record it would leave the shop holding cash it cannot explain. What
     * stops that from overselling is `TransitionOrderStatus`, which refuses to
     * move an order that has already expired and says so (`P2-E1-T4`).
     */
    public function isVerifiable(): bool
    {
        return $this->isOpen();
    }

    /**
     * Why this payment may not be verified, or null when it may be.
     *
     * One source for the endpoint's refusal and for the `can.verify` flag the
     * screens read, so a button is never offered for something the server will
     * turn down — the pattern `Branch::deletionBlockedReason()` set.
     */
    public function verificationBlockedReason(): ?string
    {
        if ($this->isVerifiable()) {
            return null;
        }

        return "Pembayaran ini sudah berstatus \"{$this->status->label()}\" dan tidak dapat diubah lagi.";
    }

    /**
     * The public URL of the uploaded receipt, when there is one.
     */
    public function proofUrl(): ?string
    {
        return $this->proof_path === null
            ? null
            : Storage::disk(self::PROOF_DISK)->url($this->proof_path);
    }

    /**
     * Attempts still waiting for money.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function open(Builder $query): Builder
    {
        return $query->where('status', PaymentTransactionStatus::Pending);
    }

    /**
     * Attempts whose deadline has passed, for the sweep that expires them.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function overrun(Builder $query, ?CarbonInterface $asOf = null): Builder
    {
        return $query->open()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $asOf ?? now());
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function latestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('id');
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
