<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Goods arriving at a warehouse (P1-E4-T5).
     *
     * A document, not a movement: it is typed as a draft, checked, and only
     * then **posted**, which is the moment `StockLedger` moves the figures and
     * writes the `entry_in` rows that explain them. A posted document is never
     * edited and never deleted, so `posted_by` and `posted_at` record who
     * committed to it.
     *
     * `code` is a human-readable document number (`IN-20261105-0001`) from
     * `App\Support\DocumentNumber`, unique across the whole company so staff
     * can quote it on the phone without naming their branch.
     *
     * `supplier_name` is a text snapshot rather than an FK: there is no
     * supplier table in Phase 1, and the name on the delivery note is what the
     * document is about. `reference_no` is the supplier's own number for it.
     */
    public function up(): void
    {
        Schema::create('stock_entries', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('supplier_name')->nullable();
            $table->string('reference_no')->nullable();
            $table->date('entry_date');
            $table->string('status')->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            // The listing: one branch's documents, newest first, filtered by status.
            $table->index(['branch_id', 'status', 'entry_date']);
            $table->index(['warehouse_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_entries');
    }
};
