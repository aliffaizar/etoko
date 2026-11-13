<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The invoice an order is given when it is paid for (P2-E6-T1).
     *
     * One per order, which the unique `order_id` enforces rather than the
     * action that writes it: two status checks reaching `paid` for the same
     * order at once must end with one invoice, and the index is the referee
     * (ADR-059).
     *
     * `invoice_number` and `issued_at` are the two facts that must never
     * change once a customer has the document: they are what a bookkeeper
     * files it under. `total` is copied from `orders.grand_total` at issue for
     * the same reason — the order's figure is already a snapshot (ADR-007),
     * and the invoice keeps its own so it can be checked against it.
     *
     * No `branch_id` and no branch scope: an invoice is only ever reached
     * through its order, which already carries both. No `pdf_path` either —
     * the PDF is drawn from these rows on every download (ADR-059).
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('invoice_number')->unique();
            $table->timestamp('issued_at');
            $table->unsignedBigInteger('total');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
