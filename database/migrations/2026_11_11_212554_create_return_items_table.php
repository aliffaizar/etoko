<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One line of a return — so many units of one order line (P2-E4-T1).
     *
     * `order_item_id` is `restrictOnDelete`: a line that has been sent back is
     * part of the order's financial record, and order lines are never deleted
     * anyway (`OrderItem` is deliberately not editable). It is the link that
     * makes the line describable without copying the whole snapshot again —
     * the product name, the SKU and the unit price are read through it, which
     * is correct because they are already snapshots of checkout (ADR-007).
     *
     * Unique on `(return_id, order_item_id)`: a request that lists the same
     * order line twice is a request whose quantities nobody can add up, and
     * the index refuses it rather than the validator alone.
     *
     * `condition` is filled when the parcel is **opened**, not when the return
     * is requested: a customer may say the goods are damaged and the branch
     * may find them fine, and it is the branch's count that decides whether
     * the units go back on sale. It defaults to `good` so the receiving screen
     * has something to show, and `ReceiveReturn` writes what was actually
     * found.
     *
     * `restock` is what the branch *intends*; whether it happened is
     * `restocked_at`, and the two are deliberately separate columns. Damaged
     * goods are never restocked whatever the flag says, a line whose variant
     * has since been hard-deleted from the catalogue has nowhere to go back
     * to, and a receiving run that is retried must not put the same units on
     * the shelf twice — `restocked_at` is what makes that last one
     * unwritable.
     *
     * `restock_warehouse_id` records *where* they went back, because "which
     * shelf did these units land on?" is a stock question and the ledger row
     * alone is not reachable from this line.
     */
    public function up(): void
    {
        Schema::create('return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained('returns')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('quantity');
            $table->string('condition')->default('good');
            $table->boolean('restock')->default(true);

            $table->foreignId('restock_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->timestamp('restocked_at')->nullable();

            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['return_id', 'order_item_id']);
            // "How many of this line have ever been sent back?" — the sum that
            // keeps two returns from together exceeding what was bought.
            $table->index('order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_items');
    }
};
