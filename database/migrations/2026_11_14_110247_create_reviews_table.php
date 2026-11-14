<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A customer's review of a product they bought (P2-E8-T3, ADR-068).
     *
     * - `order_item_id` ties the review to the purchase it is about. A review
     *   exists only for a delivered or completed purchase, so the column is
     *   filled whenever a row is written; it is nullable only because an
     *   order line can be removed by the order's own history (nullOnDelete).
     * - One review per purchased line: `(user_id, order_item_id)` is unique.
     * - `branch_id` is copied from the order, so the branch that sold the
     *   goods is the one that can reply.
     * - `status`: `pending` until moderated; only `approved` is shown and
     *   counted in the product's rating.
     * - `reply` is the seller's answer, written by one of the people who may
     *   reply; `replied_by` says who.
     */
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 120)->nullable();
            $table->text('comment')->nullable();
            $table->boolean('is_verified_purchase')->default(false);
            $table->string('status', 20)->default('pending');
            $table->text('reply')->nullable();
            $table->foreignId('replied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('replied_at')->nullable();
            $table->unsignedInteger('helpful_count')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'order_item_id']);
            $table->index(['product_id', 'status']);
            $table->index(['branch_id', 'status']);
        });

        Schema::create('review_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('review_images');
        Schema::dropIfExists('reviews');
    }
};
