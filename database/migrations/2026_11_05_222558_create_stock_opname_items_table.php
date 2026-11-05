<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One variant being counted (P1-E4-T7).
     *
     * `system_quantity` is a **snapshot** of what the system believed when the
     * line was added to the session. It is kept so the variance can be read
     * later — "we thought 12, we found 9" — and so the adjustment posted at
     * completion is the variance rather than the counted figure. Stock that
     * moved legitimately while the shelves were being walked is therefore
     * preserved instead of being counted away.
     *
     * `counted_quantity` is null until somebody actually counts that line,
     * which is what separates "not counted yet" from "counted zero" — two very
     * different findings.
     *
     * The difference is **not stored**: it is `counted_quantity -
     * system_quantity`, and a stored copy is one more thing that can disagree
     * with the two columns it came from.
     */
    public function up(): void
    {
        Schema::create('stock_opname_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_opname_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->integer('system_quantity');
            $table->integer('counted_quantity')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['stock_opname_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_items');
    }
};
