<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The expedition companies the shop ships with (P2-E2-T1).
     *
     * Reference data shared by every branch, exactly like `payment_methods`
     * and for the same reason: "JNE" is one arrangement company-wide, and
     * *where* a courier may be used is a rule rather than an owner. The rules
     * live in `shipping_rates` (which branch it ships from, which destination
     * it reaches), not in a `branch_id` on this row.
     *
     * `driver` is which code carries the work out (ADR-004), and it is a
     * different question from which company the parcel goes with: the same
     * "JNE" row is served by the rate table today and can be served by an
     * aggregator's API tomorrow by changing this one column, with no screen or
     * action touched.
     *
     * `config` holds what differs per courier and has no column of its own,
     * read through accessors on the model rather than dug into by callers:
     *
     * - `biteship` (`{courier_code, service_codes}`) — how this row maps onto
     *   an aggregator's own vocabulary (`P2-E2-T6`). Their `courier_code` is
     *   not necessarily our `code`, and pretending it is would make the
     *   mapping invisible until a request failed.
     * - `tracking_url` — a template with `{tracking_number}` in it, so the
     *   customer's tracking page can link to the courier's own page without
     *   this application knowing a URL per company.
     */
    public function up(): void
    {
        Schema::create('couriers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('logo_path')->nullable();
            $table->string('driver')->default('manual');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('config')->nullable();
            $table->timestamps();

            // The checkout list: active couriers, in the order to offer them.
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('couriers');
    }
};
