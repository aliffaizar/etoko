<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One line of an order (P1-E7-T1).
     *
     * Every descriptive column is a **snapshot**: `sku`, `product_name`,
     * `variant_name`, `image_path`, `unit_price`, `weight_gram`. An order is a
     * record of what was sold at a price, under a name, at a weight — renaming
     * a product or raising its price next month must not rewrite it (ADR-007).
     * That is also why `product_id` and `product_variant_id` are nullable with
     * `nullOnDelete`: a deleted catalogue row loses its link, not the line.
     *
     * `subtotal` is stored as `unit_price * quantity - discount_amount` so the
     * figure the order header was built from stays legible per line.
     *
     * `cost_price` is the cost at the moment of sale, for margin reporting
     * (Phase 2). Nullable: a product with no recorded cost has no margin to
     * report, which is different from a margin of the full price.
     *
     * `refunded_quantity` counts units sent back by `returns` (Phase 2) and is
     * what separates a partial refund from a full one.
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();

            $table->string('sku');
            $table->string('product_name');
            $table->string('variant_name')->nullable();
            $table->string('image_path')->nullable();

            $table->unsignedBigInteger('unit_price');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('subtotal');
            $table->unsignedInteger('weight_gram')->default(0);
            $table->unsignedBigInteger('cost_price')->nullable();
            $table->unsignedInteger('refunded_quantity')->default(0);

            $table->timestamps();

            $table->index(['order_id', 'product_variant_id']);
            // "How many of this variant have we sold?" — the best-seller sort.
            $table->index('product_variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
