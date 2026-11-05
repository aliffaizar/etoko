<?php

namespace App\Actions\Inventory;

use App\Enums\StockOpnameStatus;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves the figures counted on the shelves, without finishing the count
 * (P1-E4-T7).
 *
 * Saving and completing are separate on purpose: a large count spans hours or
 * days, and somebody has to be able to type in what they have so far, go home,
 * and come back to it. Nothing here touches stock — `CompleteStockOpname` is
 * what posts the variances.
 *
 * Saving the first figure moves the session from drafted to in progress, so
 * the listing can tell a session nobody has started from one somebody is
 * halfway through.
 *
 * A line sent as null is **uncounted**, not counted-zero. Clearing a figure
 * typed by mistake therefore has to stay possible, which is why null is an
 * accepted value rather than a missing key.
 */
final class RecordStockOpnameCounts
{
    /**
     * @param  array<int, array{id: int, counted_quantity: int|null, notes?: string|null}>  $counts
     * @return int How many lines were touched.
     *
     * @throws ValidationException When the session has already been completed or cancelled.
     */
    public function handle(StockOpname $opname, array $counts): int
    {
        if (! $opname->isEditable()) {
            throw ValidationException::withMessages([
                'status' => "Sesi opname {$opname->code} berstatus {$opname->status->label()} dan tidak bisa diubah lagi.",
            ]);
        }

        return DB::transaction(function () use ($opname, $counts): int {
            $lines = $opname->items()->get()->keyBy('id');
            $touched = 0;

            foreach ($counts as $count) {
                $line = $lines->get($count['id']);

                // A line belonging to another session is ignored rather than
                // refused: the ids arrive from a form, and silently writing to
                // somebody else's count would be the worse failure.
                if (! $line instanceof StockOpnameItem) {
                    continue;
                }

                $line->update([
                    'counted_quantity' => $count['counted_quantity'],
                    'notes' => $count['notes'] ?? null,
                ]);

                $touched++;
            }

            if ($opname->status === StockOpnameStatus::Draft && $touched > 0) {
                $opname->update(['status' => StockOpnameStatus::InProgress]);
            }

            return $touched;
        });
    }
}
