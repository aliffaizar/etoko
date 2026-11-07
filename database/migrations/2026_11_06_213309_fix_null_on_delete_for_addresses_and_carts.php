<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gives five foreign keys the `ON DELETE SET NULL` they were always meant
     * to have (found while building `P1-E7-T1`).
     *
     * The `P1-E6` migrations wrote `->nullable()->nullOnDelete()->constrained()`.
     * `nullOnDelete()` belongs to the **foreign key**, which `constrained()`
     * has not created yet at that point, so Laravel's fluent builder recorded
     * it as an unused attribute and the constraint was created with the
     * default rule instead. MySQL shows `NO ACTION` on all five, which is the
     * opposite of what each one needs:
     *
     * - `addresses.province_id/city_id/district_id` — the migration's own note
     *   says the region FKs are nullable "so a stale region row does not
     *   prevent deletion". As created, a region row could not be deleted at
     *   all once an address pointed at it, which the Indonesian region dataset
     *   in `P2-E2-T3` will replace wholesale.
     * - `carts.user_id` — deleting an account would be refused by its cart
     *   rather than letting the cart outlive it.
     * - `carts.branch_id` — deleting a branch would be refused by any cart
     *   still pointing at it. Branch deletion is a soft delete today, so
     *   nothing has hit this yet; it would the first time a branch was pruned
     *   for real.
     *
     * The source migrations are corrected too, so a fresh install creates the
     * right rule outright; this one brings an existing database into line and
     * is a harmless rewrite of an already-correct rule.
     */
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            foreach (['province_id', 'city_id', 'district_id'] as $column) {
                $table->dropForeign([$column]);
                $table->foreign($column)->references('id')->on('regions')->nullOnDelete();
            }
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->dropForeign(['branch_id']);
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            foreach (['province_id', 'city_id', 'district_id'] as $column) {
                $table->dropForeign([$column]);
                $table->foreign($column)->references('id')->on('regions');
            }
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users');

            $table->dropForeign(['branch_id']);
            $table->foreign('branch_id')->references('id')->on('branches');
        });
    }
};
