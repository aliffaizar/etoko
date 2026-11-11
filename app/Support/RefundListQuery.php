<?php

namespace App\Support;

use App\Models\Refund;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The refund listing's filters, as a query (P2-E4-T4).
 *
 * The same arrangement as `ReturnListQuery`, and not pre-narrowed to a branch
 * for the same reason: `Refund` carries `BelongsToBranch`, so a branch account
 * already sees only what went back against its own orders (BR-3).
 *
 * Sorting is whitelisted here rather than taken from the request, so a crafted
 * `sort` can never reach the `ORDER BY`.
 */
final class RefundListQuery
{
    /**
     * The columns a listing may be sorted by, as `sort` value => column.
     *
     * @var array<string, string>
     */
    public const SORTS = [
        'created_at' => 'created_at',
        'amount' => 'amount',
        'processed_at' => 'processed_at',
    ];

    public const DEFAULT_SORT = 'created_at';

    /**
     * @param  array{search: string, status: string, method: string, branch: string, from: string, until: string, sort: string, direction: string}  $filters
     * @return Builder<Refund>
     */
    public static function from(array $filters): Builder
    {
        $query = Refund::query()
            ->with([
                'order:id,order_number,customer_name,payment_status,grand_total',
                'branch:id,name',
                'return:id,code',
                'payment:id,reference',
                'processedBy:id,name',
            ]);

        self::applySearch($query, $filters['search']);

        $query
            ->when($filters['status'] !== '', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['method'] !== '', fn (Builder $q) => $q->where('method', $filters['method']))
            ->when($filters['branch'] !== '', fn (Builder $q) => $q->where('branch_id', (int) $filters['branch']))
            ->when($filters['from'] !== '', fn (Builder $q) => $q
                ->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay()))
            ->when($filters['until'] !== '', fn (Builder $q) => $q
                ->where('created_at', '<=', Carbon::parse($filters['until'])->endOfDay()));

        return self::applySort($query, $filters['sort'], $filters['direction']);
    }

    /**
     * Search over the refund number, the bank reference somebody is chasing,
     * or the order it belongs to.
     *
     * @param  Builder<Refund>  $query
     */
    private static function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(fn (Builder $match) => $match
            ->where('code', 'like', "%{$search}%")
            ->orWhere('reference', 'like', "%{$search}%")
            ->orWhereHas('order', fn (Builder $order) => $order
                ->where('order_number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")));
    }

    /**
     * @param  Builder<Refund>  $query
     * @return Builder<Refund>
     */
    private static function applySort(Builder $query, string $sort, string $direction): Builder
    {
        $column = self::SORTS[$sort] ?? self::SORTS[self::DEFAULT_SORT];
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($column, $direction)->orderByDesc('id');
    }
}
