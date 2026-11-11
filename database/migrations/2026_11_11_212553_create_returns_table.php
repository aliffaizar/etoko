<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One request to send goods back (P2-E4-T1).
     *
     * A **document**, like a goods-out note is: it has a number somebody can
     * quote on the phone, a state machine, the person who decided, and the
     * moment each thing happened. An order may have several — a customer who
     * sends one item back in March and another in May has two returns, not a
     * column that got overwritten — and `order_items.refunded_quantity` is
     * what keeps the two from together exceeding what was bought.
     *
     * `branch_id` is denormalised from the order (ADR-038, the same choice as
     * `payments` and `shipments`): it is what lets this table carry
     * `BelongsToBranch`, so a branch's return queue is already its own and
     * another branch's return id in the URL answers 404 rather than 403
     * (ADR-027). The goods come back to the branch that sent them out, so the
     * copy is also the right answer on its own terms.
     *
     * `user_id` is nullable and `nullOnDelete` because it names the *actor*,
     * not the owner: a customer who closes their account does not erase the
     * returns the shop has to account for. Which order it belongs to is the
     * `order_id`, and that is not nullable.
     *
     * `reason` is a fixed list (`ReturnReason`) and `description` is the
     * customer's own words. Both, rather than one: the list is what makes
     * "how many parcels arrived damaged last month?" answerable, and the text
     * is what makes a particular case actionable.
     *
     * `photo_paths` is a json array rather than a `return_images` table
     * (ADR-044). The photos of one request are only ever read as the set
     * belonging to that request — never ordered, never captioned, never
     * queried on their own — so a table would buy joins and nothing else.
     */
    public function up(): void
    {
        Schema::create('returns', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();

            $table->string('type')->default('refund');
            $table->string('reason');
            $table->text('description')->nullable();
            $table->json('photo_paths')->nullable();

            $table->string('status')->default('requested');

            $table->timestamp('requested_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->string('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // "What has this order had sent back?" — read by the customer's
            // own order page and by the panel's order detail.
            $table->index(['order_id', 'id']);
            // One branch's return queue, by standing — the listing's default.
            $table->index(['branch_id', 'status']);
            // The central queue, oldest request first.
            $table->index(['status', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('returns');
    }
};
