<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generates sequential, human-readable document numbers such as ORD-20261102-0001.
 *
 * The counter lives in `document_counters` and is incremented while holding a row
 * lock, so two concurrent requests can never receive the same number. Counters
 * reset every day, per prefix.
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
     * Reserve and return the next number for the given prefix.
     */
    public static function next(string $prefix, ?Carbon $date = null): string
    {
        $period = ($date ?? now())->format('Ymd');

        $number = DB::transaction(function () use ($prefix, $period): int {
            $counter = DB::table('document_counters')
                ->where('prefix', $prefix)
                ->where('period', $period)
                ->lockForUpdate()
                ->first();

            if ($counter === null) {
                DB::table('document_counters')->insert([
                    'prefix' => $prefix,
                    'period' => $period,
                    'last_number' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return 1;
            }

            $next = (int) $counter->last_number + 1;

            DB::table('document_counters')
                ->where('id', $counter->id)
                ->update(['last_number' => $next, 'updated_at' => now()]);

            return $next;
        });

        return sprintf('%s-%s-%04d', $prefix, $period, $number);
    }
}
