<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Branch\UpdateBranchOperatingHours;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateBranchOperatingHoursRequest;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * A branch's weekly opening hours (P1-E2-T4).
 *
 * The whole week is submitted at once from the branch screen, so there is only
 * one action here. Opening hours belong to the branch, which is why the branch
 * decides who may change them (`BranchPolicy::update`).
 */
class BranchOperatingHourController extends Controller
{
    public function update(
        UpdateBranchOperatingHoursRequest $request,
        Branch $branch,
        UpdateBranchOperatingHours $updateBranchOperatingHours,
    ): RedirectResponse {
        $this->authorize('update', $branch);

        $updateBranchOperatingHours->handle($branch, $request->operatingHours());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Jam operasional berhasil disimpan.']);

        return back();
    }
}
