<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One variant on a stock transfer, with a figure for every step (P2-E3-T1).
     *
     * Four quantities rather than one, because each step may legitimately
     * answer differently and the difference is the information:
     *
     * - `quantity_requested` — what the destination asked for.
     * - `quantity_approved` — what the origin agreed to send; may be less.
     * - `quantity_shipped` — what actually left; may be less again, if the
     *   shelf turned out short between approving and packing.
     * - `quantity_received` — what arrived. Anything short of shipped is the
     *   transit discrepancy, computed rather than stored
     *   (`StockTransferItem::discrepancy()`).
     *
     * Each stays null until its step has happened, so "not decided yet" and
     * "decided zero" are different values.
     *
     * The variant is referenced with `RESTRICT`: these lines explain ledger
     * rows, and variants are soft-deleted in normal use.
     */
    public function up(): void
    {
        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained();
            $table->unsignedInteger('quantity_requested');
            $table->unsignedInteger('quantity_approved')->nullable();
            $table->unsignedInteger('quantity_shipped')->nullable();
            $table->unsignedInteger('quantity_received')->nullable();
            $table->timestamps();

            $table->unique(['stock_transfer_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
    }
};
