<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One movement of money back to the customer (P2-E4-T1, T4).
     *
     * A **movement**, not a balance, for the same reason `payments` is one
     * attempt rather than a total: a refund paid in two instalments, or a
     * gateway reversal that failed and was then made by hand, leaves two rows
     * and that is the point. How much of an order has actually gone back is
     * the **sum of the `processed` rows** (`Order::refundedAmount()`) — a
     * pending reversal must not make an order read as paid back before it is.
     *
     * `return_id` is nullable, and the null case is real rather than defensive
     * (ADR-045): money goes back without goods coming back whenever there were
     * no goods to send — a delivery fee refunded on a parcel that never
     * arrived, a duplicate transfer, a cancellation after payment. The order
     * is what a refund always belongs to, so `order_id` is not nullable.
     *
     * `payment_id` names the attempt the money came in on, and it is what a
     * gateway refund needs: the provider will only reverse a transaction it
     * knows about. Nullable because a refund made by hand does not need one,
     * and `nullOnDelete` would be wrong here — a payment that has been
     * refunded is not deletable at all — so it is `restrictOnDelete` like the
     * order line on a return.
     *
     * `branch_id` is denormalised from the order (ADR-038), as on `payments`,
     * `shipments` and `returns`: it carries `BelongsToBranch`, so a branch
     * reading its own refunds needs no join and another branch's refund id
     * answers 404 rather than 403 (ADR-027). Branch staff cannot *make* a
     * refund — `refunds.process` is central (docs/04-rbac.md) — but they do
     * have to see what was returned against their own orders.
     *
     * `account_info` is where the customer wants the money: a bank, a number
     * and a holder, typed by whoever took the call. Free text rather than
     * three columns, because it is transcribed from a conversation and
     * sometimes is not a bank account at all. It is **never** used to send
     * money automatically; a person reads it.
     */
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('return_id')->nullable()->constrained('returns')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();

            $table->unsignedBigInteger('amount');
            $table->string('method');
            $table->string('status')->default('pending');

            // Theirs: the gateway's reversal id, or the transfer reference a
            // staff member read off the bank's screen.
            $table->string('reference')->nullable()->index();
            $table->text('account_info')->nullable();

            $table->string('failure_reason')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // "What has gone back on this order?" — the sum every screen and
            // every refund decision reads.
            $table->index(['order_id', 'status']);
            // The refund queue: one branch's, and centrally by standing.
            $table->index(['branch_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
