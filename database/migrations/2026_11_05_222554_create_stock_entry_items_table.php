<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One line of a goods-in document (P1-E4-T5).
     *
     * `quantity` is always positive: the direction is the document's business,
     * not the line's, and a negative line on a goods-in note would be a
     * goods-out note hiding inside one.
     *
     * `unit_cost` is whole rupiah (ADR-008) and optional, because a delivery
     * note does not always carry prices. It is copied onto the ledger row at
     * posting, which is what later stock valuation reads.
     *
     * Unique `(stock_entry_id, product_variant_id)`: the same variant twice on
     * one note is a typo, and summing it silently would hide the typo.
     */
    public function up(): void
    {
        Schema::create('stock_entry_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_cost')->nullable();
            $table->timestamps();

            $table->unique(['stock_entry_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_entry_items');
    }
};
