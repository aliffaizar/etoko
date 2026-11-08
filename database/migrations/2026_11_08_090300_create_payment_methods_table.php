<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ways a customer may pay (P2-E1-T1).
     *
     * Reference data, not transactional: one row per way of paying, shared by
     * every branch. There is no `branch_id`, because "BCA transfer" is the same
     * arrangement company-wide; where a method is *allowed* is a rule rather
     * than an owner, and rules live in `config` (see below).
     *
     * `type` and `driver` are two different questions and both are needed.
     * `type` is what the customer sees and what the flow does — a virtual
     * account shows a number, a QRIS shows a code, a COD shows nothing and
     * waits for the courier. `driver` is which code carries it out, so the
     * same type can be served manually today and by Midtrans tomorrow without
     * a single screen changing (ADR-004).
     *
     * `config` holds the things that differ per method and have no column of
     * their own, and it is read through accessors on the model rather than
     * dug into by callers:
     *
     * - `bank` (`{name, account_number, account_holder}`) — where a manual
     *   transfer should be sent; it is what the instructions screen prints.
     * - `branch_ids`, `cities`, `provinces` — the eligibility fence
     *   (`P2-E1-T7`). An absent or empty list means "everywhere", so a method
     *   that is open to the whole company carries no configuration at all.
     * - `fulfillment_types` — a method that only makes sense for one way of
     *   receiving goods; COD names `delivery`, because there is nobody to pay
     *   on the doorstep of a shop you walked into.
     * - `midtrans` (`{enabled_payments: [...]}`) — which Snap channels to open
     *   for this method, so choosing "QRIS" does not land the customer on a
     *   page offering nine other things (`P2-E1-T5`).
     *
     * `fee_flat` and `fee_percent` belong to the **method**, and the money they
     * produce belongs to the **payment** rather than the order (ADR-039):
     * `grand_total` is what the customer agreed to pay for goods, and a fee
     * that changes when they switch from a VA to a bank transfer cannot live
     * in a column of the order.
     *
     * `min_amount` / `max_amount` are nullable because "no floor" and "no
     * ceiling" are the normal case, and a 0 ceiling would read as "nothing may
     * be paid this way".
     */
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type');
            $table->string('driver')->default('manual');
            $table->string('provider_code')->nullable();
            $table->text('instructions')->nullable();
            $table->string('logo_path')->nullable();
            $table->unsignedBigInteger('fee_flat')->default(0);
            $table->decimal('fee_percent', 5, 2)->default(0);
            $table->unsignedBigInteger('min_amount')->nullable();
            $table->unsignedBigInteger('max_amount')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('config')->nullable();
            $table->timestamps();

            // The checkout list: the active methods, in the order they should
            // be offered.
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
