<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Collection;

/**
 * The branch and warehouse an inventory screen is working in (P1-E4-T3).
 *
 * Stock is always held somewhere specific, so every inventory screen needs
 * both answers before it can show a single figure. Who decides them differs by
 * role, and that difference is what this class exists to settle once:
 *
 * - **Branch staff** work in their own branch, full stop. They may not name
 *   another one, and `BranchContext` has already narrowed their queries to it
 *   anyway (BR-3).
 * - **Central staff** supervise every branch, so they pick one with `?branch=`.
 *   With nothing named they land in the default branch, because a screen that
 *   opened empty until a selector was touched would read as "no stock".
 *
 * The warehouse works the same way with `?warehouse=`, defaulting to the
 * branch's own default warehouse. A warehouse of another branch is refused
 * with a 404 rather than silently swapped, so a stale link never quietly shows
 * somebody else's shelves.
 *
 * `Branch` carries no global scope — it *is* the branch — so the narrowing for
 * branch staff is written out explicitly here, the way every other branch
 * query in the application has to.
 */
final class BranchWorkspace
{
    /**
     * @param  Collection<int, Warehouse>  $warehouses  Every warehouse of the branch, for the selector.
     */
    private function __construct(
        public readonly Branch $branch,
        public readonly Warehouse $warehouse,
        public readonly Collection $warehouses,
        public readonly bool $mayChooseBranch,
    ) {}

    /**
     * @param  int|null  $branchId  The `?branch=` a central actor asked for.
     * @param  int|null  $warehouseId  The `?warehouse=` the screen asked for.
     */
    public static function resolve(User $actor, ?int $branchId = null, ?int $warehouseId = null): self
    {
        $branch = self::resolveBranch($actor, $branchId);

        /** @var Collection<int, Warehouse> $warehouses */
        $warehouses = Warehouse::acrossBranches()
            ->where('branch_id', $branch->getKey())
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->get();

        abort_if(
            $warehouses->isEmpty(),
            404,
            "Cabang {$branch->name} belum punya gudang, jadi belum ada tempat untuk menyimpan stok.",
        );

        return new self(
            $branch,
            self::resolveWarehouse($warehouses, $warehouseId),
            $warehouses,
            $actor->isCentral(),
        );
    }

    private static function resolveBranch(User $actor, ?int $branchId): Branch
    {
        if (! $actor->isCentral()) {
            // The `branch` middleware has already refused a branch-bound
            // account with no branch, so this always finds one.
            return Branch::query()->findOrFail($actor->branch_id);
        }

        if ($branchId !== null) {
            return Branch::query()->findOrFail($branchId);
        }

        return Branch::query()->where('is_default', true)->firstOrFail();
    }

    /**
     * @param  Collection<int, Warehouse>  $warehouses
     */
    private static function resolveWarehouse(Collection $warehouses, ?int $warehouseId): Warehouse
    {
        if ($warehouseId === null) {
            return $warehouses->first();
        }

        $chosen = $warehouses->firstWhere('id', $warehouseId);

        // A 404 and not a fallback: a link carrying another branch's warehouse
        // must not quietly show the shelves of the branch the actor is in.
        abort_if($chosen === null, 404);

        return $chosen;
    }

    /**
     * The branch and warehouse as the screens send them to Inertia.
     *
     * @return array{
     *     branch: array{id: int, name: string, code: string},
     *     warehouse: array{id: int, name: string, code: string, is_default: bool},
     *     warehouses: array<int, array{value: string, label: string}>,
     *     branches: array<int, array{value: string, label: string}>,
     *     may_choose_branch: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'branch' => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'code' => $this->branch->code,
            ],
            'warehouse' => [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
                'code' => $this->warehouse->code,
                'is_default' => $this->warehouse->is_default,
            ],
            'warehouses' => $this->warehouses
                ->map(fn (Warehouse $warehouse): array => [
                    'value' => (string) $warehouse->id,
                    'label' => $warehouse->is_default
                        ? "{$warehouse->name} (utama)"
                        : $warehouse->name,
                ])
                ->values()
                ->all(),
            // Only a central actor gets a branch selector; branch staff have
            // exactly one branch and no choice to offer (BR-3).
            'branches' => $this->mayChooseBranch ? $this->branchOptions() : [],
            'may_choose_branch' => $this->mayChooseBranch,
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function branchOptions(): array
    {
        return Branch::query()
            ->ordered()
            ->get(['id', 'name', 'code'])
            ->map(fn (Branch $branch): array => [
                'value' => (string) $branch->id,
                'label' => "{$branch->name} ({$branch->code})",
            ])
            ->all();
    }
}
