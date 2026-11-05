<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A physical stock count (P1-E4-T7).
     *
     * Counting takes time, so a count has one more state than a goods
     * document: drafted, in progress while the shelves are walked, and
     * completed only when the differences reach the ledger. The middle state
     * exists so somebody can stop for the day without that reading as a
     * finished count.
     *
     * `counted_at` is when the shelves were walked, which is not necessarily
     * when the session was completed in the system — the difference matters
     * when a count is typed up the following morning.
     *
     * `code` is `OPN-20261105-0001` from `App\Support\DocumentNumber`.
     */
    public function up(): void
    {
        Schema::create('stock_opnames', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('draft');
            $table->date('counted_at');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'counted_at']);
            $table->index(['warehouse_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opnames');
    }
};
