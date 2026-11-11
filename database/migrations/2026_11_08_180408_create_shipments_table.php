<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One dispatch of an order's goods (P2-E2-T1).
     *
     * A **dispatch**, not a column on the order — and deliberately not unique
     * per order, for the same reason `payments` is not: a parcel that comes
     * back is re-sent, and "we handed this order to a courier twice, and the
     * first one was returned" is a question support has to be able to answer.
     * Nothing reopens a returned shipment; the second attempt is a second row,
     * and `Order::activeShipment()` is the one still in play.
     *
     * `branch_id` is denormalised from the order (ADR-038, the same choice as
     * `payments`): it is what lets this table carry `BelongsToBranch` like
     * every other transactional one, so a branch's dispatch queue is already
     * its own and another branch's shipment id answers 404 rather than 403
     * (ADR-027). `AssignOrderBranch` moves an order's shipments with it, so
     * the copy cannot drift.
     *
     * `courier_id` and `courier_service_id` are **nullable** while
     * `courier_name` and `service_name` are not (ADR-007): the snapshot is
     * what a delivery note from last month is read from, and a courier the
     * shop has stopped using must not blank out its own history. The nullable
     * live FKs are for asking the current arrangement — which driver to call
     * to track this parcel.
     *
     * `cost` is what the customer was charged, copied from the quote they
     * accepted, and it is **not** recomputed: a rate table edited next week
     * must not rewrite what an order's delivery cost. `weight_gram` is the
     * weight the quote was made against, so a dispute about the charge can be
     * reconstructed from the two numbers that produced it.
     *
     * `tracking_number` is indexed but **not unique**: couriers reuse waybill
     * numbers across years, and a unique index would reject the second parcel
     * rather than the mistake. What makes a number meaningful is the courier
     * beside it.
     */
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('courier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('courier_service_id')->nullable()->constrained()->nullOnDelete();

            // Snapshots of the courier, as it was when the goods went out.
            $table->string('courier_name');
            $table->string('service_name');

            $table->string('tracking_number')->nullable();
            $table->unsignedBigInteger('cost')->default(0);
            $table->unsignedInteger('weight_gram')->default(0);
            $table->string('status')->default('pending');

            // Days promised at the moment of quoting, so "it is late" can be
            // answered without asking a rate table that may since have changed.
            $table->unsignedSmallInteger('etd_min_day')->nullable();
            $table->unsignedSmallInteger('etd_max_day')->nullable();

            // The aggregator's own id for this booking, when one booked it.
            $table->string('external_id')->nullable()->index();

            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('tracking_synced_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->string('notes')->nullable();

            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();

            $table->json('raw_payload')->nullable();
            $table->timestamps();

            // "How did this order go out?" — every detail screen reads the
            // order's dispatches newest first.
            $table->index(['order_id', 'id']);
            // One branch's dispatch queue, by standing.
            $table->index(['branch_id', 'status']);
            // The sweep that asks couriers for new scans (P2-E2-T6).
            $table->index(['status', 'tracking_synced_at']);
            $table->index('tracking_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
