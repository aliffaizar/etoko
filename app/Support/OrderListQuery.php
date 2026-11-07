<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The order listing's filters, as a query (P1-E7-T8).
 *
 * One place for the panel listing's narrowing, so the screen, the counts above
 * it and any later export cannot disagree about what "pesanan bulan ini"
 * means.
 *
 * The query is **not** pre-narrowed to a branch: `Order` carries
 * `BelongsToBranch`, so a branch account is already narrowed to its own orders
 * before this adds anything (BR-3), and a central account sees every branch
 * with the `branch` filter as an ordinary filter rather than a context switch.
 * That is the difference between this and the inventory screens, where the
 * branch names a *place* a screen is looking at (`BranchWorkspace`) rather
 * than a value it is filtering by.
 *
 * Sorting is whitelisted here rather than taken from the request, so a crafted
 * `sort` can never reach the `ORDER BY`.
 */
final class OrderListQuery
{
    /**
     * The columns a listing may be sorted by, as `sort` value => column.
     *
     * @var array<string, string>
     */
    public const SORTS = [
        'created_at' => 'created_at',
        'grand_total' => 'grand_total',
        'order_number' => 'order_number',
    ];

    public const DEFAULT_SORT = 'created_at';

    /**
     * @param  array{search: string, status: string, payment_status: string, fulfillment_type: string, branch: string, from: string, until: string, sort: string, direction: string}  $filters
     * @return Builder<Order>
     */
    public static function from(array $filters): Builder
    {
        $query = Order::query()
            ->with(['branch:id,name'])
            ->withSum('items', 'quantity');

        self::applySearch($query, $filters['search']);

        $query
            ->when($filters['status'] !== '', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['payment_status'] !== '', fn (Builder $q) => $q
                ->where('payment_status', $filters['payment_status']))
            ->when($filters['fulfillment_type'] !== '', fn (Builder $q) => $q
                ->where('fulfillment_type', $filters['fulfillment_type']))
            ->when($filters['branch'] !== '', fn (Builder $q) => $q->where('branch_id', (int) $filters['branch']))
            ->when($filters['from'] !== '', fn (Builder $q) => $q
                ->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay()))
            ->when($filters['until'] !== '', fn (Builder $q) => $q
                ->where('created_at', '<=', Carbon::parse($filters['until'])->endOfDay()));

        return self::applySort($query, $filters['sort'], $filters['direction']);
    }

    /**
     * Search over what somebody reading a listing actually has to hand: the
     * order number they were quoted, or the customer who rang up.
     *
     * @param  Builder<Order>  $query
     */
    private static function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(fn (Builder $match) => $match
            ->where('order_number', 'like', "%{$search}%")
            ->orWhere('customer_name', 'like', "%{$search}%")
            ->orWhere('customer_email', 'like', "%{$search}%")
            ->orWhere('customer_phone', 'like', "%{$search}%"));
    }

    /**
     * Sort by one of the whitelisted columns, always tie-broken by id so two
     * orders placed in the same second never swap places between pages.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private static function applySort(Builder $query, string $sort, string $direction): Builder
    {
        $column = self::SORTS[$sort] ?? self::SORTS[self::DEFAULT_SORT];
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($column, $direction)->orderByDesc('id');
    }
}
