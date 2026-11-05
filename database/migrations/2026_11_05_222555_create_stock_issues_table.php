<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Goods leaving a warehouse without being sold (P1-E4-T6).
     *
     * The mirror image of `stock_entries`, and deliberately a separate table
     * rather than a sign on a shared one: a goods-out note has to say **why**,
     * and that question does not exist for goods coming in. One table with a
     * nullable reason would make the required field optional for half its
     * rows.
     *
     * Stock sold is not an issue: it leaves through an order as a `sale_out`
     * movement (P1-E7).
     *
     * `code` is `OUT-20261105-0001` from `App\Support\DocumentNumber`.
     */
    public function up(): void
    {
        Schema::create('stock_issues', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('reason');
            $table->string('reference_no')->nullable();
            $table->date('issue_date');
            $table->string('status')->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'issue_date']);
            // "How much did we lose to damage this month?" reads the table this way.
            $table->index(['branch_id', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_issues');
    }
};
