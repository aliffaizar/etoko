<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock moving from one branch's warehouse to another's (P2-E3-T1).
     *
     * Unlike every other stock document this one has **two** branches, so it
     * carries no single `branch_id` and no `BelongsToBranch`: it is visible to
     * the branch it comes from and to the branch it goes to, and to nobody
     * else (docs/04-rbac.md, `StockTransferBranchScope`).
     *
     * Branches and warehouses are referenced with the default `RESTRICT`
     * rather than a cascade. A transfer that has shipped explains ledger rows
     * in both warehouses; deleting one end must not quietly take the
     * document with it. `Branch::deletionBlockedReason()` and
     * `Warehouse::deletionBlockedReason()` say so before the database does.
     *
     * Each step records who did it and when, because each is done by a
     * different party: the destination requests and receives, the origin (or
     * central) approves and ships.
     *
     * `code` is `TRF-20261113-0001` from `App\Support\DocumentNumber`.
     */
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('from_branch_id')->constrained('branches');
            $table->foreignId('from_warehouse_id')->constrained('warehouses');
            $table->foreignId('to_branch_id')->constrained('branches');
            $table->foreignId('to_warehouse_id')->constrained('warehouses');
            $table->string('status')->default('requested');
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('shipped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('shipped_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->text('receipt_notes')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // "What is waiting for me?" is asked from both ends.
            $table->index(['from_branch_id', 'status']);
            $table->index(['to_branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
    }
};
