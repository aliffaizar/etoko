<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A customer order (P1-E7-T1).
     *
     * `branch_id` is NOT NULL because BR-5 requires every transaction to name
     * the branch that fulfils it: that is the branch whose stock is reserved,
     * whose staff pick it, and whose figures it lands in. An order with no
     * branch would be stock nobody holds.
     *
     * `user_id` is nullable: a POS walk-in (Phase 3) has no account, and the
     * customer snapshot columns are what the order is addressed to either way.
     * Those snapshots — name, email, phone, and the whole `shipping_*` block —
     * are copies on purpose. A customer who later edits their address must not
     * silently rewrite where an order already shipped (ADR-007).
     *
     * Money is whole rupiah in `unsignedBigInteger` (BR-10). `grand_total` is
     * stored rather than derived because it is what the customer agreed to pay
     * and what a payment gateway is charged against; recomputing it from the
     * items would quietly change history when a rounding rule changes.
     *
     * The timestamp columns (`placed_at` … `cancelled_at`) are the moments the
     * order *reached* each stage, written by `TransitionOrderStatus`. They are
     * denormalised from `order_status_histories` so a listing can sort by "paid
     * when?" without a subquery; the history remains the full account.
     *
     * `voucher_id` has no FK constraint yet — `vouchers` arrives in Phase 2.
     * `voucher_code` is the snapshot that survives the voucher being deleted.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('channel')->default('online');
            $table->string('fulfillment_type');
            $table->string('status')->default('pending_payment');
            $table->string('payment_status')->default('unpaid');

            // Customer snapshot: who the order is for, as it was at checkout.
            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();

            // Shipping snapshot: null throughout for a pickup order.
            $table->string('shipping_recipient')->nullable();
            $table->string('shipping_phone')->nullable();
            $table->text('shipping_address_line')->nullable();
            $table->string('shipping_district_name')->nullable();
            $table->string('shipping_city_name')->nullable();
            $table->string('shipping_province_name')->nullable();
            $table->string('shipping_postal_code')->nullable();
            $table->decimal('shipping_latitude', 10, 7)->nullable();
            $table->decimal('shipping_longitude', 10, 7)->nullable();

            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('discount_total')->default(0);
            $table->unsignedBigInteger('shipping_cost')->default(0);
            $table->unsignedBigInteger('tax_total')->default(0);
            $table->unsignedBigInteger('grand_total');
            $table->unsignedInteger('total_weight_gram')->default(0);

            $table->unsignedBigInteger('voucher_id')->nullable();
            $table->string('voucher_code')->nullable();

            $table->text('customer_note')->nullable();
            $table->text('internal_note')->nullable();

            $table->timestamp('placed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // "What is open at my branch?" — the branch order queue.
            $table->index(['branch_id', 'status']);
            // "What have I ordered?" — the customer's own history.
            $table->index(['user_id', 'created_at']);
            // Central listings, sorted newest first within a status.
            $table->index(['status', 'created_at']);
            $table->index(['payment_status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
