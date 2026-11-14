<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per order that redeemed a voucher (P2-E5-T4). Unique
     * `(voucher_id, order_id)`, so one order can never count twice against a
     * quota however often its checkout is retried.
     *
     * `released_at` is set when the order is cancelled or expires: the unit of
     * quota and the customer's allowance come back, and the row stays as the
     * record of what happened. Every count that enforces a limit reads
     * `released_at IS NULL` only.
     *
     * `voucher_id` restricts deletion: a voucher that has been redeemed is
     * deactivated, not deleted (`Voucher::deletionBlockedReason()`), so this
     * history cannot be orphaned by a click in the panel.
     */
    public function up(): void
    {
        Schema::create('voucher_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('discount_amount');
            $table->timestamp('used_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['voucher_id', 'order_id']);
            $table->index(['voucher_id', 'user_id', 'released_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voucher_usages');
    }
};
