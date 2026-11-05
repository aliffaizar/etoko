<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A branch's own price for one variant (P1-E3-T10).
     *
     * Priced per variant and not per product, because that is the grain
     * everything downstream buys at: a cart line and an order line carry a
     * variant id, so a price that could not be told apart per variant could
     * not be charged.
     *
     * `starts_at` / `ends_at` make a price scheduled; both null means it holds
     * for as long as it is active. `EffectivePriceResolver` weighs this against
     * the variant's and the product's price (BR-8).
     *
     * Money is whole rupiah in integer columns (ADR-008, BR-10).
     */
    public function up(): void
    {
        Schema::create('branch_product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('price');
            $table->unsignedBigInteger('compare_at_price')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'product_variant_id']);
            // The resolver's lookup: one branch, one variant, in force now.
            //
            // Named explicitly: the name Laravel derives from these four
            // columns is 65 characters and MySQL's limit is 64, so the
            // migration fails without it — the same trap
            // `product_variant_attribute_values` hit (docs/03-data-model.md).
            $table->index(['branch_id', 'is_active', 'starts_at', 'ends_at'], 'branch_prices_in_force_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_product_prices');
    }
};
