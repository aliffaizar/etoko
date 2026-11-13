<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pays off LOW-04 and LOW-05 (docs/07-progress.md), due with P2-E3.
     *
     * **LOW-04.** `stocks.product_variant_id` and
     * `stock_movements.product_variant_id` were created with
     * `ON DELETE CASCADE`, so force-deleting a variant would have erased its
     * ledger — the one record that explains every figure the variant ever
     * had. Variants are soft-deleted in normal use; this makes the hard delete
     * refuse instead of quietly taking the history with it.
     *
     * **LOW-05 (partly).** `StockLedger` already refuses a negative on-hand
     * figure and a negative reservation. The database now refuses them too,
     * so a write that bypasses the ledger (a raw query, a future bug) fails
     * loudly instead of producing a figure no movement explains. MySQL only:
     * SQLite cannot add a CHECK to an existing table, and it is only the test
     * database here.
     *
     * `reserved_quantity <= quantity` is deliberately **not** added yet: an
     * existing test (`PaymentControllerTest`, "the shelf can no longer cover
     * the order") arranges exactly that state to prove `VerifyPayment`
     * refuses it, and changing that test needs the user's say (LOW-05 in
     * docs/07-progress.md).
     */
    private const CHECKS = [
        'stocks_quantity_not_negative' => 'quantity >= 0',
        'stocks_reserved_not_negative' => 'reserved_quantity >= 0',
    ];

    public function up(): void
    {
        foreach (['stocks', 'stock_movements'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['product_variant_id']);
                $table->foreign('product_variant_id')->references('id')->on('product_variants')->restrictOnDelete();
            });
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::CHECKS as $name => $expression) {
            DB::statement("ALTER TABLE stocks ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (array_keys(self::CHECKS) as $name) {
                DB::statement("ALTER TABLE stocks DROP CHECK {$name}");
            }
        }

        foreach (['stocks', 'stock_movements'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['product_variant_id']);
                $table->foreign('product_variant_id')->references('id')->on('product_variants')->cascadeOnDelete();
            });
        }
    }
};
