<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock promised to one buyer and not yet handed over (P1-E7-T1, BR-6).
     *
     * `stocks.reserved_quantity` is the *total* being held in a warehouse;
     * this table is the itemised account of who is holding what, and it is
     * what makes a hold releasable. Without it, an abandoned checkout would
     * leave an anonymous number in `reserved_quantity` that nothing could ever
     * give back.
     *
     * The two are written together, in one transaction, by
     * `ReserveStock` / `CommitReservation` / `ReleaseReservation`, which reach
     * the figures through `StockLedger` — the only writer of `stocks`
     * (ADR-024).
     *
     * `status` moves one way only: `active` → `committed` (the goods left the
     * shelf and the sale is final) or `active` → `released` (the hold expired
     * or the order was cancelled). A row is never deleted, so "why did this
     * hold disappear?" always has an answer.
     *
     * `order_id` and `cart_id` are both nullable because a hold is taken for
     * one of the two: an order while it awaits payment, or a cart during a
     * timed checkout flow (Phase 2). Exactly one is set in practice.
     *
     * `expires_at` is what `ExpireStockReservations` sweeps on, which is why
     * `(status, expires_at)` is indexed: the job asks for active holds past
     * their moment, once a minute, and must not scan the table to find them.
     */
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('cart_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status')->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // The sweep: active holds whose moment has passed.
            $table->index(['status', 'expires_at']);
            // Releasing or committing an order's holds, all at once.
            $table->index(['order_id', 'status']);
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
