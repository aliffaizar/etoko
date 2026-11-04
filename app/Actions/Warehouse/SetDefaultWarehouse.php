<?php

namespace App\Actions\Warehouse;

use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Makes one warehouse the branch's default, demoting the previous one.
 *
 * Stock moves through the default warehouse unless a document names another, so
 * exactly one warehouse per branch has to carry the flag. Promotion and
 * demotion happen in one transaction to keep that true.
 */
final class SetDefaultWarehouse
{
    public function handle(Warehouse $warehouse): Warehouse
    {
        return DB::transaction(function () use ($warehouse): Warehouse {
            Warehouse::query()
                ->where('branch_id', $warehouse->branch_id)
                ->whereKeyNot($warehouse->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);

            // A default warehouse has to stay usable, otherwise stock would have
            // nowhere to go.
            $warehouse->fill(['is_default' => true, 'is_active' => true])->save();

            return $warehouse;
        });
    }
}
