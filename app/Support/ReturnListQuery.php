<?php

namespace App\Support;

use App\Models\OrderReturn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The return queue's filters, as a query (P2-E4-T3).
 *
 * One place for the panel listing's narrowing, so the screen and the counts
 * above it cannot disagree about what "menunggu persetujuan" means — the same
 * arrangement as `OrderListQuery` and `PaymentListQuery`.
 *
 * The query is **not** pre-narrowed to a branch: `OrderReturn` carries
 * `BelongsToBranch`, so a branch account is already narrowed to its own
 * requests before this adds anything (BR-3), and a central account sees every
 * branch with `branch` as an ordinary filter.
 *
 * Sorting is whitelisted here rather than taken from the request, so a crafted
 * `sort` can never reach the `ORDER BY`.
 */
final class ReturnListQuery
{
    /**
     * The columns a listing may be sorted by, as `sort` value => column.
     *
     * @var array<string, string>
     */
    public const SORTS = [
        'requested_at' => 'requested_at',
        'created_at' => 'created_at',
        'code' => 'code',
    ];

    public const DEFAULT_SORT = 'requested_at';

    /**
     * @param  array{search: string, status: string, type: string, reason: string, branch: string, open: string, from: string, until: string, sort: string, direction: string}  $filters
     * @return Builder<OrderReturn>
     */
    public static function from(array $filters): Builder
    {
        $query = OrderReturn::query()
            ->with([
                'order:id,order_number,customer_name,status,payment_status,grand_total',
                'branch:id,name',
            ])
            ->withSum('items', 'quantity');

        self::applySearch($query, $filters['search']);

        $query
            ->when($filters['status'] !== '', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['type'] !== '', fn (Builder $q) => $q->where('type', $filters['type']))
            ->when($filters['reason'] !== '', fn (Builder $q) => $q->where('reason', $filters['reason']))
            ->when($filters['branch'] !== '', fn (Builder $q) => $q->where('branch_id', (int) $filters['branch']))
            // "What still needs doing?" — the one question the queue exists to
            // answer, and the one a plain status filter cannot: three of the
            // six statuses await somebody, and which three is a rule rather
            // than a choice (`ReturnStatus::openStatuses()`).
            ->when($filters['open'] === '1', fn (Builder $q) => $q->open())
            ->when($filters['from'] !== '', fn (Builder $q) => $q
                ->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay()))
            ->when($filters['until'] !== '', fn (Builder $q) => $q
                ->where('created_at', '<=', Carbon::parse($filters['until'])->endOfDay()));

        return self::applySort($query, $filters['sort'], $filters['direction']);
    }

    /**
     * Search over what somebody reading the queue has to hand: the return
     * number or the order number a customer quoted, or their name.
     *
     * The order number lives on another table, so it is matched through the
     * relation — which keeps the branch scope on `Order` applying to it as
     * well, and that is harmless here because `returns.branch_id` has already
     * narrowed the rows.
     *
     * @param  Builder<OrderReturn>  $query
     */
    private static function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(fn (Builder $match) => $match
            ->where('code', 'like', "%{$search}%")
            ->orWhereHas('order', fn (Builder $order) => $order
                ->where('order_number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")));
    }

    /**
     * Sort by one of the whitelisted columns, always tie-broken by id so two
     * requests made in the same second never swap places between pages.
     *
     * @param  Builder<OrderReturn>  $query
     * @return Builder<OrderReturn>
     */
    private static function applySort(Builder $query, string $sort, string $direction): Builder
    {
        $column = self::SORTS[$sort] ?? self::SORTS[self::DEFAULT_SORT];
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($column, $direction)->orderByDesc('id');
    }
}
