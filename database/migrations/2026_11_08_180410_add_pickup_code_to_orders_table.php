<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The code somebody says at the counter to collect their order
     * (P2-E2-T7).
     *
     * Click & Collect needs an answer to "is this the person whose order this
     * is?", and the order number is not it: it is printed on the email, the
     * page and the chat message, and anybody who has seen one of those knows
     * it. The code is issued only when the goods are actually waiting, shown
     * only to the customer, and asked for once.
     *
     * Nullable because most orders never have one: it is written by
     * `TransitionOrderStatus` at the moment an order reaches
     * `ready_for_pickup` and never before — a code generated at checkout would
     * be a code sitting in a page for a fortnight before it meant anything.
     *
     * **Not unique.** It is a short confirmation asked for alongside the order
     * it belongs to, not an identifier to look an order up by; uniqueness
     * across every order the shop has ever taken would force longer codes for
     * no gain. `ConfirmOrderPickup` checks it against *this* order.
     *
     * `ready_at` goes in with it because `ready_for_pickup` was the one status
     * in the state machine with no moment of its own, which left "how long has
     * this been sitting on the shelf?" unanswerable without reading the
     * timeline — and that is the question a branch with a crowded collection
     * shelf actually asks.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('pickup_code', 10)->nullable()->after('voucher_code');
            $table->timestamp('ready_at')->nullable()->after('processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['pickup_code', 'ready_at']);
        });
    }
};
