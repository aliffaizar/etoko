<?php

namespace App\Actions\Branch;

use App\Models\Branch;
use App\Models\BranchOperatingHour;
use Illuminate\Support\Facades\DB;

/**
 * Replaces a branch's weekly opening hours (P1-E2-T4).
 *
 * Every branch keeps exactly seven rows, one per day, so the storefront can
 * answer "is this branch open now?" with a single lookup and never has to treat
 * a missing row as a special case. The rows are therefore updated in place
 * rather than deleted and re-inserted.
 *
 * A day marked closed loses its times, so a branch can never be both closed and
 * carrying stale opening hours.
 */
final class UpdateBranchOperatingHours
{
    /**
     * @param  array<int, array{day_of_week: int, opens_at?: string|null, closes_at?: string|null, is_closed?: bool}>  $hours
     */
    public function handle(Branch $branch, array $hours): void
    {
        DB::transaction(function () use ($branch, $hours): void {
            foreach ($hours as $hour) {
                $isClosed = (bool) ($hour['is_closed'] ?? false);

                $branch->operatingHours()->updateOrCreate(
                    ['day_of_week' => $hour['day_of_week']],
                    [
                        'is_closed' => $isClosed,
                        'opens_at' => $isClosed ? null : BranchOperatingHour::normalizeTime($hour['opens_at'] ?? null),
                        'closes_at' => $isClosed ? null : BranchOperatingHour::normalizeTime($hour['closes_at'] ?? null),
                    ],
                );
            }
        });
    }
}
