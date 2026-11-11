<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One service level of a courier (P2-E2-T1).
     *
     * "JNE REG" and "JNE YES" are the same company and two different promises,
     * priced differently and arriving on different days, so they are two rows
     * rather than one row with a column. What the customer picks at checkout
     * is a *service*, never a courier: "ship with JNE" does not say what it
     * costs or when it lands.
     *
     * `etd_min_day` / `etd_max_day` are the promise in days, both nullable
     * because a courier that quotes no estimate is the normal case for a
     * same-day or instant service — and a 0 would read as "arrives today",
     * which is a claim we would be making on its behalf.
     *
     * `courier_id` is `cascadeOnDelete`: a service has no meaning without its
     * courier. Shipments do not depend on it surviving — they carry
     * `courier_name` and `service_name` snapshots (ADR-007) and a nullable FK,
     * so deleting a courier the shop no longer uses leaves last month's
     * delivery notes reading exactly as they did.
     *
     * Unique `(courier_id, code)`: "REG" means one thing per courier and two
     * rows claiming it would make the aggregator mapping ambiguous.
     */
    public function up(): void
    {
        Schema::create('courier_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('etd_min_day')->nullable();
            $table->unsignedSmallInteger('etd_max_day')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['courier_id', 'code']);
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_services');
    }
};
