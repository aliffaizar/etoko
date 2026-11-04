<?php

namespace App\Actions\Warehouse;

use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Updates a warehouse's details (P1-E2-T6).
 *
 * Promoting it to the branch default is delegated, so the "exactly one default"
 * rule lives in a single place. Clearing the flag directly is refused by
 * UpdateWarehouseRequest; another warehouse has to be promoted instead.
 */
final class UpdateWarehouse
{
    public function __construct(private SetDefaultWarehouse $setDefaultWarehouse) {}

    /**
     * @param  array{code: string, name: string, address?: string|null, is_default?: bool, is_active?: bool}  $attributes
     */
    public function handle(Warehouse $warehouse, array $attributes): Warehouse
    {
        return DB::transaction(function () use ($warehouse, $attributes): Warehouse {
            $shouldBeDefault = (bool) ($attributes['is_default'] ?? $warehouse->is_default);

            $warehouse->fill([
                'code' => $attributes['code'],
                'name' => $attributes['name'],
                'address' => $attributes['address'] ?? null,
                'is_active' => $shouldBeDefault ? true : ($attributes['is_active'] ?? $warehouse->is_active),
            ])->save();

            if ($shouldBeDefault) {
                $this->setDefaultWarehouse->handle($warehouse);
            }

            return $warehouse;
        });
    }
}
