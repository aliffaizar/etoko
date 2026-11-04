<?php

namespace App\Actions\Branch;

use App\Models\Branch;
use App\Models\BranchOperatingHour;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a branch together with the records it cannot operate without.
 *
 * Every branch owns at least one warehouse, because stock is held per warehouse
 * (ADR-005), and a full week of operating hours so the storefront always knows
 * whether the branch is open.
 */
final class CreateBranch
{
    public const DEFAULT_WAREHOUSE_CODE = 'WH-01';

    public const DEFAULT_OPENS_AT = '08:00';

    public const DEFAULT_CLOSES_AT = '17:00';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes): Branch
    {
        return DB::transaction(function () use ($attributes): Branch {
            $branch = Branch::create([
                ...$attributes,
                'slug' => $attributes['slug'] ?? $this->uniqueSlug((string) $attributes['name']),
                // The first branch becomes the storefront fallback.
                'is_default' => $attributes['is_default'] ?? ! Branch::where('is_default', true)->exists(),
            ]);

            $branch->warehouses()->create([
                'code' => self::DEFAULT_WAREHOUSE_CODE,
                'name' => 'Gudang Utama',
                'address' => $branch->address_line,
                'is_default' => true,
                'is_active' => true,
            ]);

            $branch->operatingHours()->createMany($this->defaultOperatingHours());

            return $branch;
        });
    }

    /**
     * Open Monday to Saturday, closed on Sunday, until an admin says otherwise.
     *
     * @return array<int, array{day_of_week: int, opens_at: string|null, closes_at: string|null, is_closed: bool}>
     */
    private function defaultOperatingHours(): array
    {
        return array_map(fn (int $day): array => [
            'day_of_week' => $day,
            'opens_at' => $day === 0 ? null : BranchOperatingHour::normalizeTime(self::DEFAULT_OPENS_AT),
            'closes_at' => $day === 0 ? null : BranchOperatingHour::normalizeTime(self::DEFAULT_CLOSES_AT),
            'is_closed' => $day === 0,
        ], range(0, 6));
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while (Branch::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
