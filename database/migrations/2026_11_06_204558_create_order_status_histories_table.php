<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every status an order has held, in order (P1-E7-T1).
     *
     * Append-only, like `stock_movements`: rows are written by
     * `TransitionOrderStatus` and never updated or deleted, so the timeline the
     * customer and the branch read is the actual sequence of events rather than
     * a reconstruction. There is therefore no `updated_at` — a row that could
     * be edited is not a trail.
     *
     * `from_status` is nullable: the first row records the order coming into
     * existence, which has no status to come from.
     *
     * `changed_by` is nullable because not every transition has a person behind
     * it — `ExpireStockReservations` and a payment webhook both move an order
     * with nobody signed in.
     */
    public function up(): void
    {
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('note')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
    }
};
