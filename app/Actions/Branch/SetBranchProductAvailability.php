<?php

namespace App\Actions\Branch;

use App\Models\Branch;
use App\Models\BranchProduct;
use Illuminate\Support\Facades\DB;

/**
 * Says whether a branch sells the given products (P1-E3-T9).
 *
 * Only the deviations are stored (see `BranchProduct`), so marking a product
 * available again does not write `is_available = true` — it deletes the row
 * and lets the branch fall back to the default. A table full of rows saying
 * what the default already says would make "which products has this branch
 * actually switched off?" a question nobody could answer at a glance.
 *
 * Notes are the exception worth keeping a row for: "tidak ada permintaan" on
 * an available product is a record someone wrote on purpose, so a row with a
 * note stays even when the product is available.
 *
 * The whole selection is applied in one transaction: a bulk change that fails
 * halfway would leave a branch's catalogue in a state nobody asked for.
 */
final class SetBranchProductAvailability
{
    /**
     * @param  array<int, int>  $productIds
     * @return int How many products the call actually changed.
     */
    public function handle(Branch $branch, array $productIds, bool $isAvailable, ?string $notes = null): int
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        if ($productIds === []) {
            return 0;
        }

        return DB::transaction(function () use ($branch, $productIds, $isAvailable, $notes): int {
            $existing = BranchProduct::query()
                ->where('branch_id', $branch->getKey())
                ->whereIn('product_id', $productIds)
                ->get()
                ->keyBy('product_id');

            $changed = 0;

            foreach ($productIds as $productId) {
                $row = $existing->get($productId);
                $keepsRow = ! $isAvailable || ($notes !== null && $notes !== '');

                if (! $keepsRow) {
                    // Back to the default, and the row with it — so the table
                    // holds exceptions only.
                    if ($row instanceof BranchProduct) {
                        $row->delete();
                        $changed++;
                    }

                    continue;
                }

                if ($row instanceof BranchProduct) {
                    $row->fill(['is_available' => $isAvailable, 'notes' => $notes]);

                    if ($row->isDirty()) {
                        $row->save();
                        $changed++;
                    }

                    continue;
                }

                BranchProduct::create([
                    'branch_id' => $branch->getKey(),
                    'product_id' => $productId,
                    'is_available' => $isAvailable,
                    'notes' => $notes,
                ]);
                $changed++;
            }

            return $changed;
        });
    }
}
