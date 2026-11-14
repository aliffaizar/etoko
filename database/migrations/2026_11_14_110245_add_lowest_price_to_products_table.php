<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The catalogue's price, stored on the product (P2-E8-T2, ADR-066).
     *
     * `lowest_price` is the cheapest catalogue price among the product's
     * active variants, as `EffectivePriceResolver` prices them with no branch —
     * promotions included. It is NULL when no variant is active. Only
     * `RefreshLowestPrice` writes it. Sorting and price-filtering the storefront
     * read this column, so the index below is what makes "termurah" a range
     * scan rather than a subquery per product.
     *
     * The backfill covers the current catalogue. No promotion exists yet when
     * this runs, so the variant or base price is the whole answer.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('lowest_price')->nullable()->after('base_price');
            $table->index(['lowest_price', 'id']);
        });

        DB::statement(<<<'SQL'
            UPDATE products SET lowest_price = (
                SELECT MIN(COALESCE(product_variants.price, products.base_price))
                FROM product_variants
                WHERE product_variants.product_id = products.id
                  AND product_variants.is_active = 1
                  AND product_variants.deleted_at IS NULL
            )
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['lowest_price', 'id']);
            $table->dropColumn('lowest_price');
        });
    }
};
