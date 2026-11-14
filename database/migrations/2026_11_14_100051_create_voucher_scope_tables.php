<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Where and on what a voucher may be used (P2-E5-T1):
     *
     * - `voucher_branches` — no rows means every branch.
     * - `voucher_categories` / `voucher_products` — read only when
     *   `vouchers.applies_to` says so; the other list is emptied on save, so a
     *   voucher never carries a rule nobody can see on its screen.
     *
     * Every side cascades: removing a branch, product or category from the
     * catalogue simply narrows the vouchers that named it.
     */
    public function up(): void
    {
        Schema::create('voucher_branches', function (Blueprint $table) {
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();

            $table->primary(['voucher_id', 'branch_id']);
        });

        Schema::create('voucher_categories', function (Blueprint $table) {
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();

            $table->primary(['voucher_id', 'category_id']);
        });

        Schema::create('voucher_products', function (Blueprint $table) {
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->primary(['voucher_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voucher_products');
        Schema::dropIfExists('voucher_categories');
        Schema::dropIfExists('voucher_branches');
    }
};
