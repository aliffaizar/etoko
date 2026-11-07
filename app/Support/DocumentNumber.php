<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Generates sequential, human-readable document numbers such as ORD-20261102-0001.
 *
 * The counter lives in `document_counters` and is handed out in two steps that
 * are deliberately in this order (HIGH-02 in docs/reviews/phase-1-review.md):
 *
 * 1. **Make sure today's counter row exists**, with a plain read followed, only
 *    if it is missing, by a plain `INSERT` whose duplicate-key error is
 *    swallowed. Neither statement is a locking read, which is the whole point:
 *    the previous version ran `SELECT … FOR UPDATE` against a row that did not
 *    exist yet, and InnoDB answers a locking read that matches nothing with a
 *    **gap lock** on the index. Two requests could hold that gap together and
 *    then both ask for the insert-intention lock inside it, so the first
 *    document of every prefix every day deadlocked under concurrency: five
 *    parallel checkouts produced one number and four HTTP 500s. Letting the
 *    unique index referee the race instead — the pattern
 *    `StockLedger::lockedStock()` already uses — removes the gap entirely.
 * 2. **Increment it and read the result back** inside one short transaction.
 *    The row now certainly exists, so this is an ordinary exclusive row lock
 *    with no gap: concurrent callers queue on it and each reads its own number,
 *    because the lock is held until that transaction commits.
 *
 * Callers should take the number **before** opening their own transaction
 * (MED-06). A `DB::transaction()` nested inside another one is only a
 * savepoint, so the counter's row lock would otherwise be held for the whole
 * length of the caller's work — serialising every checkout in the shop behind
 * one row. A number burned by a checkout that then fails is a cheap loss;
 * Indonesian bookkeeping does not require order numbers without gaps.
 *
 * Counters reset every day, per prefix.
 */
final class DocumentNumber
{
    public const PREFIX_ORDER = 'ORD';

    public const PREFIX_INVOICE = 'INV';

    public const PREFIX_STOCK_ENTRY = 'IN';

    public const PREFIX_STOCK_ISSUE = 'OUT';

    public const PREFIX_STOCK_TRANSFER = 'TRF';

    public const PREFIX_STOCK_OPNAME = 'OPN';

    public const PREFIX_RETURN = 'RTN';

    public const PREFIX_REFUND = 'RFD';

    /**
     * How many times to try before giving up.
     *
     * More than one attempt is only ever needed when the counter row is
     * created and then deleted underneath us, which outside of tests does not
     * happen; the loop exists so that the one impossible case is an exception
     * rather than a null document code.
     */
    private const MAX_ATTEMPTS = 3;

    /**
     * Reserve and return the next number for the given prefix.
     */
    public static function next(string $prefix, ?Carbon $date = null): string
    {
        $period = ($date ?? now())->format('Ymd');

        return sprintf('%s-%s-%04d', $prefix, $period, self::reserve($prefix, $period));
    }

    /**
     * The next sequence value for a (prefix, period) pair.
     */
    private static function reserve(string $prefix, string $period): int
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            self::ensureCounterExists($prefix, $period);

            $number = self::bump($prefix, $period);

            if ($number !== null) {
                return $number;
            }
        }

        throw new RuntimeException("Tidak dapat mengambil nomor dokumen untuk {$prefix}-{$period}.");
    }

    /**
     * Create today's counter row unless it is already there.
     *
     * The read is plain on purpose — see the class docblock. The insert races
     * other requests, and losing that race is the expected outcome rather than
     * a failure: the unique index on `(prefix, period)` guarantees exactly one
     * row survives, and whoever lost simply increments the winner's row.
     */
    private static function ensureCounterExists(string $prefix, string $period): void
    {
        if (self::counterFor($prefix, $period)->exists()) {
            return;
        }

        try {
            self::counters()->insert([
                'prefix' => $prefix,
                'period' => $period,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another request created today's counter between the read above
            // and this insert. That is the row we wanted.
        }
    }

    /**
     * Increment the counter and return the value this caller got.
     *
     * The `UPDATE` takes the row's exclusive lock and the read-back happens
     * inside the same transaction, so the number returned is this caller's and
     * nobody else can take it. Returns null when there was no row to
     * increment, which puts `reserve()` round the loop again.
     */
    private static function bump(string $prefix, string $period): ?int
    {
        return DB::transaction(function () use ($prefix, $period): ?int {
            $updated = self::counterFor($prefix, $period)
                ->increment('last_number', 1, ['updated_at' => now()]);

            if ($updated === 0) {
                return null;
            }

            return (int) self::counterFor($prefix, $period)->value('last_number');
        });
    }

    private static function counterFor(string $prefix, string $period): Builder
    {
        return self::counters()
            ->where('prefix', $prefix)
            ->where('period', $period);
    }

    private static function counters(): Builder
    {
        return DB::table('document_counters');
    }
}
