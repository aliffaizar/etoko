<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Models\Category;
use App\Services\Branch\StorefrontBranch;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Props every storefront screen's shell needs (P1-E5-T2, P0-E2-T6).
 *
 * `ShopLayout` draws the chosen branch and the category menu in its header, so
 * both have to be there before any page renders — a deferred or optional prop
 * would leave the header empty on first paint.
 *
 * Shared from a middleware on the storefront group rather than from
 * `HandleInertiaRequests`, because the panels have no use for either: an admin
 * loading the product listing should not pay for the shop's category menu on
 * every request.
 */
class ShareStorefrontContext
{
    public function __construct(private StorefrontBranch $storefront) {}

    public function handle(Request $request, Closure $next): Response
    {
        Inertia::share('shop', fn (): array => [
            'branch' => $this->currentBranchProps(),
            'branches' => $this->branchOptions(),
            // False for a first-time visitor, who is being shown the main
            // branch rather than one they picked: the header invites them to
            // confirm instead of implying they already chose.
            'has_chosen_branch' => $this->storefront->hasChosen(),
            'categories' => $this->categoryMenu(),
        ]);

        return $next($request);
    }

    /**
     * The chosen branch, or null on an installation with no branch at all —
     * the one state the screens never render in, because the controllers
     * answer 503 for it.
     *
     * @return array{id: int, name: string, code: string, city_name: string|null, address_line: string, supports_delivery: bool, supports_pickup: bool}|null
     */
    private function currentBranchProps(): ?array
    {
        $branch = $this->storefront->current();

        return $branch instanceof Branch ? $this->branchProps($branch) : null;
    }

    /**
     * @return array{id: int, name: string, code: string, city_name: string|null, address_line: string, supports_delivery: bool, supports_pickup: bool}
     */
    private function branchProps(Branch $branch): array
    {
        return [
            'id' => $branch->id,
            'name' => $branch->name,
            'code' => $branch->code,
            'city_name' => $branch->city_name,
            'address_line' => $branch->address_line,
            'supports_delivery' => $branch->supports_delivery,
            'supports_pickup' => $branch->supports_pickup,
        ];
    }

    /**
     * The branches the picker offers, each with what it supports, so a shopper
     * who needs delivery can tell before choosing.
     *
     * @return array<int, array{id: int, name: string, code: string, city_name: string|null, address_line: string, supports_delivery: bool, supports_pickup: bool, has_coordinates: bool}>
     */
    private function branchOptions(): array
    {
        return $this->storefront->sellingBranches()
            ->map(fn (Branch $branch): array => [
                ...$this->branchProps($branch),
                // The nearest-branch search can only rank branches that have a
                // position, so the picker says when none of them has one.
                'has_coordinates' => $branch->latitude !== null && $branch->longitude !== null,
            ])
            ->values()
            ->all();
    }

    /**
     * The header's category menu: active roots with their active children.
     *
     * Two levels and not three — a third would need a menu inside a menu, and
     * the catalogue's own category filter already reaches the leaves, where
     * picking a parent includes everything below it.
     *
     * One query over a small table, grouped in memory. Switching a category
     * off switches its whole subtree off (ADR-019), so filtering by
     * `is_active` alone cannot leave a child hanging under a hidden parent.
     *
     * @return array<int, array{name: string, slug: string, icon: string|null, children: array<int, array{name: string, slug: string}>}>
     */
    private function categoryMenu(): array
    {
        $categories = Category::query()
            ->active()
            ->ordered()
            ->get(['id', 'parent_id', 'name', 'slug', 'icon']);

        $childrenOf = $categories->whereNotNull('parent_id')->groupBy('parent_id');

        return $categories
            ->whereNull('parent_id')
            ->map(fn (Category $root): array => [
                'name' => $root->name,
                'slug' => $root->slug,
                'icon' => $root->icon,
                'children' => $childrenOf
                    ->get($root->getKey(), new Collection)
                    ->map(fn (Category $child): array => [
                        'name' => $child->name,
                        'slug' => $child->slug,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
