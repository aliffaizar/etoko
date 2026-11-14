<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A voucher is company-wide marketing data (P2-E5-T1), managed centrally,
     * so it has no `branch_id`; where it may be used is a rule held in
     * `voucher_branches` (no rows = every branch).
     *
     * - `code` is stored upper-case (`Voucher::code` mutator) and unique, so a
     *   customer's "hemat10" finds `HEMAT10`.
     * - `value` is a percentage (1–100) for `percentage`, rupiah for `fixed`,
     *   and unused for `free_shipping`, whose ceiling is `max_discount`.
     * - `used_count` is a denormalised count of live `voucher_usages`, written
     *   only under this row's lock (`RedeemVoucher`, `ReleaseVoucher`), so the
     *   quota check and the insert it guards are one decision (P2-E5-T4).
     * - `per_user_limit` 0 means no limit, the same convention as
     *   `flash_sale_items.per_user_limit`.
     * - `starts_at`/`ends_at` are both optional: no start means "from now",
     *   no end means "until switched off".
     */
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type', 20);
            $table->unsignedBigInteger('value')->default(0);
            $table->unsignedBigInteger('max_discount')->nullable();
            $table->unsignedBigInteger('min_order_amount')->default(0);
            $table->unsignedInteger('quota')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('per_user_limit')->default(1);
            $table->string('applies_to', 20)->default('all');
            $table->string('channel', 20)->default('all');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'ends_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
