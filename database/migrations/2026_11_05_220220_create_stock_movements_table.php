<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every change to on-hand stock, in the order it happened (P1-E4-T1).
     *
     * Append only. A row is never updated and never deleted: the ledger is
     * what makes a stock figure explainable, and a correction that rewrote
     * history would leave the figure unexplainable instead. A mistake is
     * undone by recording the opposite movement, not by editing the mistake.
     *
     * `quantity_change` is signed — plus for stock coming in, minus for stock
     * going out — and `quantity_before`/`quantity_after` pin the row to the
     * figure it produced, so the ledger can be reconciled against `stocks`
     * without replaying every row from the beginning.
     *
     * The morph reference points at whatever caused the movement: a
     * `StockEntry`, `StockIssue`, `StockOpname`, `StockTransfer` or `Order`.
     * It is nullable, because a manual adjustment is caused by a person and
     * their note, not by a document.
     *
     * There is no `updated_at`: a row that is never updated has nothing to
     * put in it.
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->integer('quantity_change');
            $table->integer('quantity_before');
            $table->integer('quantity_after');
            $table->nullableMorphs('reference');
            $table->unsignedBigInteger('unit_cost')->nullable();
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            // The branch's own movement feed, newest first.
            $table->index(['branch_id', 'created_at']);
            // One variant's history, which is what `/branch/stocks/{variant}/movements` reads.
            $table->index(['product_variant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
