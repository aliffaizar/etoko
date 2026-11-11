<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the courier said, and when (P2-E2-T1, T5).
     *
     * Append-only, like `order_status_histories`: the timeline the customer
     * reads is the record of what was reported, not a derivation of the
     * shipment's current status. A row is never edited, because a courier
     * correcting itself is a new scan rather than a rewritten one.
     *
     * **Unique `(shipment_id, status, event_at)`** is the whole idempotency
     * mechanism of `SyncShipmentTracking` (`P2-E2-T6`). Couriers return their
     * entire history on every call, so a sweep running every ten minutes would
     * otherwise append the same forty scans forty times a day. There is no
     * event id to key on — most Indonesian tracking APIs do not issue one — so
     * the natural key is what the scan *is*: this parcel, this status, this
     * moment. Two genuinely different scans sharing all three are
     * indistinguishable to us anyway.
     *
     * `source` records who said it, because the two sources are not equally
     * authoritative and a customer reading "Terkirim" deserves to know whether
     * the courier reported it or the branch typed it.
     *
     * `event_at` is the courier's own timestamp, never `created_at`: a sweep
     * that runs at 09:00 and learns about a 17:40 scan from yesterday must
     * place it at 17:40, or the timeline reads as though the parcel stood
     * still overnight and then moved twice.
     */
    public function up(): void
    {
        Schema::create('shipment_tracking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->string('description');
            $table->string('location')->nullable();
            $table->string('source')->default('courier');
            $table->timestamp('event_at');
            $table->timestamps();

            $table->unique(['shipment_id', 'status', 'event_at']);
            // The timeline, read oldest first.
            $table->index(['shipment_id', 'event_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_tracking_events');
    }
};
