<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How much of one variant sits in one warehouse (P1-E4-T1).
     *
     * One row per (warehouse, variant), enforced by a unique index: two rows
     * for the same pair would let the same goods be counted — and sold —
     * twice. `branch_id` is denormalised from the warehouse so a branch can
     * ask "what do I hold?" without joining, and so `BelongsToBranch` can
     * narrow the table the same way it narrows every other branch table
     * (BR-3).
     *
     * Rows are created on demand, not pre-seeded for every pair: a catalogue
     * of 5.000 variants across 10 warehouses would otherwise need 50.000 rows
     * to say "nothing here yet". A missing row therefore means zero, and
     * `Stock::quantityFor()` is the only correct way to ask.
     *
     * `quantity` is on-hand; `reserved_quantity` is what checkout is holding
     * (P1-E7). What may actually be sold is the difference — the
     * `available_quantity` accessor — and it is what anti-overselling checks
     * (BR-6).
     *
     * These columns must only be written from `App\Services\Inventory\StockLedger`,
     * which writes the matching `stock_movements` row in the same transaction.
     */
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->integer('min_quantity')->default(0);
            $table->integer('max_quantity')->nullable();
            $table->string('shelf_location')->nullable();
            $table->timestamps();

            $table->unique(['warehouse_id', 'product_variant_id']);
            $table->index(['branch_id', 'product_variant_id']);
            // "What is running low in this branch?" — the low-stock dashboard
            // and notification list both read the table this way.
            $table->index(['branch_id', 'quantity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
