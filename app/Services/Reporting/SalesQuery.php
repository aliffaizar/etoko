<?php

namespace App\Services\Reporting;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * What counts as a sale, written down once (P2-E7, ADR-070).
 *
 * **A sale is an order that has been paid for and was not cancelled, dated by
 * its `paid_at`.** Three consequences, each deliberate:
 *
 * - An order waiting for payment, or one that expired, never sold anything:
 *   its stock was only ever held, never committed.
 * - A paid order cancelled afterwards is not a sale either — the money went
 *   back and the stock came back — so it drops out of every figure rather
 *   than appearing as a sale and a refund of the same amount.
 * - A sale stays a sale when goods come back later. Returns are reported
 *   beside it (`returned`), never subtracted from the stored totals
 *   (ADR-047): gross is what was sold, net is gross minus what came back.
 *
 * Cash on delivery reaches `paid` when the order is placed (ADR-040), so a COD
 * order counts from that moment, before the courier has collected the money.
 * That is the stock view of a sale — the goods are committed — and it is the
 * one the branch acts on.
 *
 * `Order` carries the branch global scope, so a branch account's sales are its
 * own before anything here narrows them (BR-3). `OrderItem` does not, which is
 * why line queries are always written as "lines **of** these orders" rather
 * than as a join that would read past the scope.
 */
final class SalesQuery
{
    /**
     * The orders that count as sales in the period.
     *
     * @return Builder<Order>
     */
    public static function orders(ReportFilters $filters): Builder
    {
        return self::counted($filters->branchId)
            ->whereBetween('orders.paid_at', [$filters->from, $filters->until]);
    }

    /**
     * Every order that counts as a sale, whenever it was paid — for questions
     * that reach outside the period, such as "was this the customer's first
     * purchase?".
     *
     * @return Builder<Order>
     */
    public static function counted(?int $branchId = null): Builder
    {
        return Order::query()
            ->whereNotNull('orders.paid_at')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->when($branchId !== null, fn (Builder $query) => $query
                ->where('orders.branch_id', $branchId));
    }

    /**
     * The lines of those orders, narrowed to a category when one is asked for.
     *
     * A line whose product has since been deleted has no category any more
     * (`product_id` is nulled, ADR-007), so it is only counted when no
     * category is asked for.
     *
     * @return Builder<OrderItem>
     */
    public static function items(ReportFilters $filters): Builder
    {
        $categoryIds = $filters->categoryIds();

        return OrderItem::query()
            ->whereIn('order_items.order_id', self::orders($filters)->select('orders.id'))
            ->when($categoryIds !== null, fn (Builder $query) => $query
                ->whereIn('order_items.product_id', fn (QueryBuilder $products) => $products
                    ->select('id')
                    ->from('products')
                    ->whereIn('category_id', $categoryIds)));
    }

    /**
     * The value of the units that came back on a line, in rupiah.
     *
     * A line is refunded per unit (`refunded_quantity`), and its `subtotal`
     * already carries the line's share of any discount, so the value of the
     * returned units is the subtotal's share for them, rounded down. MySQL's
     * `/` answers a decimal, so it uses `DIV`; SQLite divides integers as
     * integers and has no `DIV`.
     *
     * @param  literal-string  $table
     * @return literal-string
     */
    public static function returnedValueSql(string $table = 'order_items'): string
    {
        $divide = DB::connection()->getDriverName() === 'sqlite' ? '/' : 'DIV';

        return "CASE WHEN {$table}.quantity > 0 THEN ({$table}.subtotal * {$table}.refunded_quantity) {$divide} {$table}.quantity ELSE 0 END";
    }

    /**
     * The calendar day of a timestamp column, the same in MySQL and SQLite.
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    public static function daySql(string $column): string
    {
        return "DATE({$column})";
    }
}
