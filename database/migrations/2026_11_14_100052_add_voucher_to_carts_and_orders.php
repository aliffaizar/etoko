<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * - `carts.voucher_id` — the voucher the customer applied on the cart
     *   screen, carried to checkout and re-checked there by `PlaceOrder`
     *   (P2-E5-T3). Null on delete: a voucher that disappears simply stops
     *   applying.
     * - `orders.voucher_id` finally gets the foreign key the orders migration
     *   left out because `vouchers` did not exist yet. Null on delete,
     *   because `orders.voucher_code` is the snapshot that outlives it.
     */
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->foreignId('voucher_id')->nullable()->after('branch_id')->constrained()->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('voucher_id')->references('id')->on('vouchers')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['voucher_id']);
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voucher_id');
        });
    }
};
