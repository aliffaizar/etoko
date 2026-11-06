<?php

namespace App\Services\Branch;

use App\Models\Branch;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Collection;

/**
 * The branch a shopper is shopping from (P1-E5-T2, BR-8).
 *
 * Deliberately **not** `BranchContext`. That class answers "which branch's
 * data may this member of staff touch?" and is filled from the signed-in
 * user's `branch_id`; this one answers "whose shelf and whose price list is
 * this visitor looking at?" and is filled from their own choice. A customer
 * has no `branch_id` at all, and a shopper switching branches must not change
 * what any staff account is allowed to read — so the two never share a value
 * (ADR-028).
 *
 * The choice lives in the session under `shop.branch_id`, which is what makes
 * it survive a page reload for a guest who has no account to store it on.
 * Nothing is written until the visitor actually picks one: until then they see
 * the default branch, because a storefront that showed no prices until a
 * selector was touched would read as an empty shop.
 *
 * Registered as a **scoped** binding, not a singleton: everything it holds is
 * request state, and under a long-lived worker a singleton would carry one
 * visitor's branch into the next visitor's request. Scoped instances are
 * flushed between requests, so the saving is real and the leak is not
 * possible.
 */
final class StorefrontBranch
{
    /**
     * Where the shopper's choice is kept (docs/04-rbac.md).
     */
    public const SESSION_KEY = 'shop.branch_id';

    /**
     * Branches that may be shopped from, loaded once for the picker and the
     * nearest-branch search.
     *
     * @var Collection<int, Branch>|null
     */
    private ?Collection $sellingBranches = null;

    public function __construct(private Session $session) {}

    /**
     * The branch to price and stock this request against.
     *
     * Recomputed on each call rather than memoised, because it is derived from
     * the session: caching it would mean a branch chosen earlier in the same
     * request — or in the same test — kept answering after the choice changed.
     * There is no query behind it; the branch list underneath is what is
     * loaded once.
     *
     * Null only when the company has no branch a customer may buy from at all,
     * which is a fresh installation rather than a state the storefront has to
     * render around; `currentOrFail()` is what the screens use.
     */
    public function current(): ?Branch
    {
        return $this->chosen() ?? $this->fallback();
    }

    /**
     * The current branch, refusing the request when there is none.
     *
     * A 503 and not an empty page: without a branch there is no price and no
     * stock to show (BR-8), so every figure on the screen would be invented.
     */
    public function currentOrFail(): Branch
    {
        $branch = $this->current();

        abort_if(
            $branch === null,
            503,
            'Belum ada cabang yang melayani pembelian, jadi toko belum bisa dibuka.',
        );

        return $branch;
    }

    /**
     * Remember the shopper's choice for the rest of their session.
     */
    public function choose(Branch $branch): void
    {
        $this->session->put(self::SESSION_KEY, $branch->getKey());
    }

    /**
     * Whether the visitor has actually picked a branch, as opposed to being
     * shown the default one.
     *
     * The header reads this to invite a first-time visitor to confirm where
     * they are shopping, instead of silently implying they already have.
     */
    public function hasChosen(): bool
    {
        return $this->chosen() instanceof Branch;
    }

    /**
     * The branches a shopper may buy from, in display order.
     *
     * `status = active` is the gate: a branch under maintenance or switched off
     * accepts no orders (`BranchStatus::acceptsOrders()`), so offering it would
     * be offering a shop that cannot sell.
     *
     * @return Collection<int, Branch>
     */
    public function sellingBranches(): Collection
    {
        if ($this->sellingBranches instanceof Collection) {
            return $this->sellingBranches;
        }

        /** @var Collection<int, Branch> $branches */
        $branches = Branch::query()->active()->ordered()->get();

        return $this->sellingBranches = $branches;
    }

    /**
     * The open branch closest to a point, or null when none has coordinates.
     *
     * Measured in PHP rather than in SQL on purpose. The haversine formula
     * needs `radians()`, `acos()` and friends, which SQLite only exposes when
     * it was compiled with `SQLITE_ENABLE_MATH_FUNCTIONS` — and the test suite
     * runs on SQLite (docs/02-architecture.md). A company has a handful of
     * branches, not a table to scan, so the whole set is already in memory for
     * the picker anyway and the sort costs nothing.
     */
    public function nearestTo(float $latitude, float $longitude): ?Branch
    {
        return $this->sellingBranches()
            ->filter(fn (Branch $branch): bool => $branch->latitude !== null && $branch->longitude !== null)
            ->sortBy(fn (Branch $branch): float => $this->distanceKm(
                $latitude,
                $longitude,
                (float) $branch->latitude,
                (float) $branch->longitude,
            ))
            ->first();
    }

    /**
     * The branch named in the session, when it is still one a shopper may buy
     * from.
     *
     * A branch that has since been switched off or deleted is treated as no
     * choice at all rather than as a dead end, so a session from last week
     * does not leave somebody looking at a shop that cannot sell.
     */
    private function chosen(): ?Branch
    {
        $chosenId = $this->session->get(self::SESSION_KEY);

        if (! is_int($chosenId) && ! is_string($chosenId)) {
            return null;
        }

        return $this->sellingBranches()->firstWhere('id', (int) $chosenId);
    }

    /**
     * Where a visitor who has chosen nothing shops: the main branch.
     *
     * Exactly one branch holds `is_default` and it may not be switched off
     * (ADR-017), so this normally answers. The `first()` behind it is for the
     * window during development where no branch exists yet.
     */
    private function fallback(): ?Branch
    {
        $branches = $this->sellingBranches();

        return $branches->firstWhere('is_default', true) ?? $branches->first();
    }

    /**
     * Great-circle distance between two points, in kilometres.
     */
    private function distanceKm(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $earthRadiusKm = 6371.0;

        $deltaLat = deg2rad($toLat - $fromLat);
        $deltaLng = deg2rad($toLng - $fromLng);

        $haversine = sin($deltaLat / 2) ** 2
            + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($deltaLng / 2) ** 2;

        return $earthRadiusKm * 2 * asin(min(1.0, sqrt($haversine)));
    }
}
