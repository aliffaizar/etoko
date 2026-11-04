<?php

namespace App\Actions\Warehouse;

use App\Models\Branch;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Adds a warehouse to a branch (P1-E2-T6).
 *
 * A branch without a default warehouse has nowhere to put stock, so the first
 * warehouse added becomes the default whether or not the form asked for it.
 */
final class CreateWarehouse
{
    public function __construct(private SetDefaultWarehouse $setDefaultWarehouse) {}

    /**
     * @param  array{code: string, name: string, address?: string|null, is_default?: bool, is_active?: bool}  $attributes
     */
    public function handle(Branch $branch, array $attributes): Warehouse
    {
        return DB::transaction(function () use ($branch, $attributes): Warehouse {
            $shouldBeDefault = ($attributes['is_default'] ?? false)
                || ! $branch->warehouses()->where('is_default', true)->exists();

            $warehouse = $branch->warehouses()->create([
                'code' => $attributes['code'],
                'name' => $attributes['name'],
                'address' => $attributes['address'] ?? null,
                'is_default' => false,
                'is_active' => $shouldBeDefault ? true : ($attributes['is_active'] ?? true),
            ]);

            if ($shouldBeDefault) {
                $this->setDefaultWarehouse->handle($warehouse);
            }

            return $warehouse;
        });
    }
}
