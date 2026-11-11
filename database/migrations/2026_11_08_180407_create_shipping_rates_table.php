<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the manual driver charges to carry a parcel (P2-E2-T1, T2).
     *
     * One row = one rule, from the most specific to the catch-all, and
     * `ManualProvider` picks the **most specific rule that matches**: a rate
     * written for one district beats the rate written for its province, which
     * beats the rate written for everywhere. `ShippingRateScope::specificity()`
     * is that ordering; without it "which rate applies" would depend on
     * insertion order.
     *
     * Three columns can each be left null, and null means *this rule does not
     * care*:
     *
     * - `courier_service_id` null — the rule applies to every service of every
     *   courier. The floor a shop can set in one row.
     * - `origin_branch_id` null — ships from anywhere. Set it and the rule is
     *   that branch's own price list, which is what a branch far from the
     *   capital needs.
     * - `destination_region_id` null — see below.
     *
     * **Destinations are matched by name, not by region id (ADR-043).** The
     * schema keeps `destination_region_id` as docs/03-data-model.md describes
     * it, but `destination_name` is what actually decides today: `addresses`
     * stores the province, city and district a customer **typed**, and its
     * three region foreign keys are never populated because the application
     * ships with no region dataset. A rate table keyed only on region ids
     * would therefore match nothing and every order would silently fall
     * through to the catch-all — a table that reads as real and is not. When a
     * region dataset lands, `destination_region_id` becomes the precise half
     * of the same lookup and the name stays as the fallback.
     *
     * The price is two numbers because that is how Indonesian couriers quote:
     * `flat_price` covers the first `min_weight_gram` (the "minimum 1 kg"
     * every tariff sheet opens with) and `price_per_kg` is charged for each
     * further kilogram, rounded **up**, because couriers round up.
     */
    public function up(): void
    {
        Schema::create('shipping_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_service_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('origin_branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->foreignId('destination_region_id')->nullable()->constrained('regions')->nullOnDelete();
            $table->string('destination_type')->default('all');
            $table->string('destination_name')->nullable();
            $table->unsignedBigInteger('flat_price')->default(0);
            $table->unsignedBigInteger('price_per_kg')->default(0);
            $table->unsignedInteger('min_weight_gram')->default(1000);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // The lookup `ManualProvider` runs: the rules that could apply to
            // one service, narrowed by origin before they are ranked.
            $table->index(['courier_service_id', 'is_active']);
            $table->index(['origin_branch_id', 'destination_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rates');
    }
};
