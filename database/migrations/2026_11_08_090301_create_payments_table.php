<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One attempt to collect the money for an order (P2-E1-T1).
     *
     * An **attempt**, not a balance. A customer who lets a virtual account
     * expire and then pays by transfer leaves two rows, and that is the point:
     * "we asked for this money twice, and the first ask went nowhere" is a
     * question support has to be able to answer. Nothing ever reopens a
     * finished attempt — a retry is a new row.
     *
     * `branch_id` is denormalised from the order (ADR-038) so this table can
     * carry `BelongsToBranch` like every other transactional one. Without it a
     * branch admin opening `/admin/payments/{id}` for a neighbour's payment
     * would be refused by a policy *after* the record had been found, and a
     * 403 tells them it exists. With it, route-model binding answers 404,
     * which is the property ADR-027 is built on. `AssignOrderBranch` moves the
     * order's payments with it, so the two can never drift.
     *
     * `payment_method_id` is `restrictOnDelete`: a method that has ever been
     * used is part of the shop's financial record and deleting it would erase
     * how money arrived. `method_name` and `method_type` are snapshots on top
     * of that (ADR-007) — renaming "BCA Transfer" must not rename it on a
     * receipt from last month.
     *
     * Two identifiers, because there are two parties:
     *
     * - `reference` is **ours**, generated before the gateway is called and
     *   unique across the table. It is what Midtrans is told the `order_id`
     *   is, and what its callback quotes back, so a webhook finds its payment
     *   by an exact match rather than by parsing a string together
     *   (`P2-E1-T6`). It is per *attempt*, not per order, because a gateway
     *   refuses a second transaction under an id it has already seen.
     * - `external_id` is **theirs** — the gateway's own transaction id, which
     *   only exists once they have accepted the charge.
     *
     * `amount` is what this attempt asks for: the order's `grand_total` plus
     * `fee_amount`, the method's own charge. Stored rather than derived for
     * the same reason `orders.grand_total` is: it is the figure a gateway was
     * charged against, and recomputing it later would quietly rewrite it.
     *
     * `expires_at` is the payment deadline (`P2-E1-T3`), and it is **taken
     * from the order's stock holds** rather than configured separately
     * (ADR-041): a deadline that outlived the hold would show a customer an
     * order they can still pay for whose goods have gone back on sale, which
     * is exactly the leak `HIGH-03` was.
     *
     * `raw_payload` keeps the last gateway callback, so a disputed payment can
     * be read as the gateway described it rather than as we interpreted it.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();

            // Snapshots of the method, as it was when the money was asked for.
            $table->string('method_name');
            $table->string('method_type');

            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('fee_amount')->default(0);
            $table->string('status')->default('pending');

            $table->string('reference')->unique();
            $table->string('external_id')->nullable()->index();

            // What the customer is shown in order to pay. Which of the three
            // is filled depends on the method's type; a COD has none of them.
            $table->string('va_number')->nullable();
            $table->text('qr_string')->nullable();
            $table->text('payment_url')->nullable();

            // The manual transfer trail (P2-E1-T3).
            $table->string('proof_path')->nullable();
            $table->string('proof_note')->nullable();
            $table->timestamp('proof_uploaded_at')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('failure_reason')->nullable();

            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->json('raw_payload')->nullable();
            $table->timestamps();

            // "How is this order being paid?" — the storefront and both panel
            // detail screens read the order's attempts newest first.
            $table->index(['order_id', 'id']);
            // The verification queue: one branch's attempts by standing.
            $table->index(['branch_id', 'status']);
            // The central queue, and the sweep that expires overdue attempts.
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
