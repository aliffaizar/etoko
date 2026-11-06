<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\ChooseBranchRequest;
use App\Http\Requests\Shop\FindNearestBranchRequest;
use App\Models\Branch;
use App\Services\Branch\StorefrontBranch;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Where the shopper is shopping from (P1-E5-T2).
 *
 * Two ways to answer, and they are separate endpoints because they are
 * separate intents: naming a branch, and asking which one is closest. Folding
 * them into one endpoint would mean a body that is valid in two mutually
 * exclusive shapes, and validation messages that could not say which.
 *
 * Both answer with `back()`. Switching branch changes every price and every
 * availability figure on the page the shopper is already looking at (BR-8), so
 * re-rendering that page is the point — redirecting them to the home page
 * would throw away the product they were reading about.
 */
class BranchController extends Controller
{
    /**
     * The shopper picked a branch by name.
     */
    public function update(ChooseBranchRequest $request, StorefrontBranch $storefront): RedirectResponse
    {
        $branch = Branch::query()->findOrFail($request->branchId());

        $storefront->choose($branch);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Kamu sekarang belanja dari {$branch->name}. Harga dan stok mengikuti cabang ini.",
        ]);

        return back();
    }

    /**
     * The shopper let the browser offer their location.
     *
     * When no open branch has coordinates there is nothing to rank, so the
     * choice is left alone and the shopper is told to pick manually — quietly
     * assigning them the main branch would look like the location had been
     * used.
     */
    public function nearest(FindNearestBranchRequest $request, StorefrontBranch $storefront): RedirectResponse
    {
        $nearest = $storefront->nearestTo($request->latitude(), $request->longitude());

        if (! $nearest instanceof Branch) {
            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => 'Belum ada cabang yang punya titik lokasi, jadi cabang terdekat belum bisa dihitung. Silakan pilih cabang secara manual.',
            ]);

            return back();
        }

        $storefront->choose($nearest);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Cabang terdekat dari lokasimu: {$nearest->name}. Harga dan stok mengikuti cabang ini.",
        ]);

        return back();
    }
}
