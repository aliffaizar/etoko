<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A promotion is a price rule on a set of products (P2-E8-T2, ADR-067):
     *
     * - `type` is `percentage` (`value` 1–100) or `fixed` (`value` in rupiah
     *   off the catalogue price, never below zero).
     * - `priority`: when several promotions cover one product in force at the
     *   same time, the highest priority wins; ties go to the older promotion (lower id).
     * - `promotion_products` names the products; a promotion with no products
     *   does nothing.
     * - `promotion_branches`: no rows means every branch (company-wide, and
     *   the only kind the storefront's catalogue price sees, ADR-067).
     * - `starts_at`/`ends_at` are both optional, like a voucher's.
     */
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 20);
            $table->unsignedBigInteger('value');
            $table->integer('priority')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'ends_at']);
        });

        Schema::create('promotion_products', function (Blueprint $table) {
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->primary(['promotion_id', 'product_id']);
            $table->index('product_id');
        });

        Schema::create('promotion_branches', function (Blueprint $table) {
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();

            $table->primary(['promotion_id', 'branch_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotion_branches');
        Schema::dropIfExists('promotion_products');
        Schema::dropIfExists('promotions');
    }
};
