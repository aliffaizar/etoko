<?php

namespace App\Support;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The payment queue's filters, as a query (P2-E1-T4).
 *
 * One place for the panel listing's narrowing, so the screen and the counts
 * above it cannot disagree about what "menunggu konfirmasi" means — the same
 * arrangement as `OrderListQuery`, and for the same reason.
 *
 * The query is **not** pre-narrowed to a branch: `Payment` carries
 * `BelongsToBranch`, so a branch account is already narrowed to its own
 * payments before this adds anything (BR-3), and a central account sees every
 * branch with `branch` as an ordinary filter.
 *
 * Sorting is whitelisted here rather than taken from the request, so a crafted
 * `sort` can never reach the `ORDER BY`.
 */
final class PaymentListQuery
{
    /**
     * The columns a listing may be sorted by, as `sort` value => column.
     *
     * @var array<string, string>
     */
    public const SORTS = [
        'created_at' => 'created_at',
        'amount' => 'amount',
        'expires_at' => 'expires_at',
        'proof_uploaded_at' => 'proof_uploaded_at',
    ];

    public const DEFAULT_SORT = 'created_at';

    /**
     * @param  array{search: string, status: string, method_type: string, branch: string, awaiting_proof: string, from: string, until: string, sort: string, direction: string}  $filters
     * @return Builder<Payment>
     */
    public static function from(array $filters): Builder
    {
        $query = Payment::query()
            ->with([
                'order:id,order_number,customer_name,status,payment_status,grand_total',
                'branch:id,name',
            ]);

        self::applySearch($query, $filters['search']);

        $query
            ->when($filters['status'] !== '', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['method_type'] !== '', fn (Builder $q) => $q
                ->where('method_type', $filters['method_type']))
            ->when($filters['branch'] !== '', fn (Builder $q) => $q->where('branch_id', (int) $filters['branch']))
            // "Which receipts am I waiting on?" — the one question the queue
            // exists to answer, and the one a plain status filter cannot: an
            // open attempt with a receipt attached needs a person, an open
            // attempt without one is merely a customer who has not paid yet.
            ->when($filters['awaiting_proof'] === '1', fn (Builder $q) => $q
                ->open()
                ->whereNotNull('proof_path'))
            ->when($filters['from'] !== '', fn (Builder $q) => $q
                ->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay()))
            ->when($filters['until'] !== '', fn (Builder $q) => $q
                ->where('created_at', '<=', Carbon::parse($filters['until'])->endOfDay()));

        return self::applySort($query, $filters['sort'], $filters['direction']);
    }

    /**
     * Search over what somebody reading the queue has to hand: the order
     * number a customer quoted, our own reference, or the gateway's id from a
     * support ticket.
     *
     * The order number lives on another table, so it is matched through the
     * relation — which keeps the branch scope on `Order` applying to it as
     * well, and that is harmless here because `payments.branch_id` has already
     * narrowed the rows.
     *
     * @param  Builder<Payment>  $query
     */
    private static function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(fn (Builder $match) => $match
            ->where('reference', 'like', "%{$search}%")
            ->orWhere('external_id', 'like', "%{$search}%")
            ->orWhereHas('order', fn (Builder $order) => $order
                ->where('order_number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")));
    }

    /**
     * Sort by one of the whitelisted columns, always tie-broken by id so two
     * payments made in the same second never swap places between pages.
     *
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    private static function applySort(Builder $query, string $sort, string $direction): Builder
    {
        $column = self::SORTS[$sort] ?? self::SORTS[self::DEFAULT_SORT];
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($column, $direction)->orderByDesc('id');
    }
}
