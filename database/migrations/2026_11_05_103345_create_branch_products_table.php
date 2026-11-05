<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What one branch decides about a centrally managed product (P1-E3-T9).
     *
     * Only the deviations are stored: no row means the branch follows the
     * default and sells the product. A fresh branch therefore starts with the
     * whole catalogue available without a row per product, and the table stays
     * the size of the exceptions rather than branches × products.
     */
    public function up(): void
    {
        Schema::create('branch_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_available')->default(true);
            // Why the branch is not selling it, e.g. "tidak ada permintaan".
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'product_id']);
            $table->index(['branch_id', 'is_available']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_products');
    }
};
