<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One line of a goods-out document (P1-E4-T6).
     *
     * `quantity` is positive here too, as on a goods-in line: the direction
     * belongs to the document. `StockLedger` is what turns it into a negative
     * movement at posting.
     *
     * `unit_cost` carries what the unit was worth as it left, which is what
     * makes shrinkage reportable in rupiah rather than only in units.
     */
    public function up(): void
    {
        Schema::create('stock_issue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_cost')->nullable();
            $table->timestamps();

            $table->unique(['stock_issue_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_issue_items');
    }
};
