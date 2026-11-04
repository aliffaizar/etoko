<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Warehouse\CreateWarehouse;
use App\Actions\Warehouse\SetDefaultWarehouse;
use App\Actions\Warehouse\UpdateWarehouse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreWarehouseRequest;
use App\Http\Requests\Admin\UpdateWarehouseRequest;
use App\Models\Branch;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Warehouses of one branch (P1-E2-T6).
 *
 * Warehouses have no screen of their own: they are managed on the branch they
 * belong to, so the routes are nested under the branch and authorisation asks
 * `BranchPolicy::manageWarehouses`. Scoped route bindings make a warehouse of
 * another branch a 404 rather than a silent cross-branch edit.
 */
class BranchWarehouseController extends Controller
{
    public function store(StoreWarehouseRequest $request, Branch $branch, CreateWarehouse $createWarehouse): RedirectResponse
    {
        $this->authorize('manageWarehouses', $branch);

        /** @var array{code: string, name: string, address?: string|null, is_default?: bool, is_active?: bool} $attributes */
        $attributes = $request->validated();

        $warehouse = $createWarehouse->handle($branch, $attributes);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Gudang {$warehouse->name} berhasil ditambahkan."]);

        return back();
    }

    public function update(
        UpdateWarehouseRequest $request,
        Branch $branch,
        Warehouse $warehouse,
        UpdateWarehouse $updateWarehouse,
    ): RedirectResponse {
        $this->authorize('manageWarehouses', $branch);

        /** @var array{code: string, name: string, address?: string|null, is_default?: bool, is_active?: bool} $attributes */
        $attributes = $request->validated();

        $updateWarehouse->handle($warehouse, $attributes);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Perubahan gudang berhasil disimpan.']);

        return back();
    }

    public function setDefault(
        Branch $branch,
        Warehouse $warehouse,
        SetDefaultWarehouse $setDefaultWarehouse,
    ): RedirectResponse {
        $this->authorize('manageWarehouses', $branch);

        $setDefaultWarehouse->handle($warehouse);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Gudang {$warehouse->name} kini menjadi gudang utama cabang ini.",
        ]);

        return back();
    }

    /**
     * Removes a warehouse. The branch's default warehouse stays, because stock
     * has to have somewhere to live; hand the flag to another warehouse first.
     */
    public function destroy(Branch $branch, Warehouse $warehouse): RedirectResponse
    {
        $this->authorize('manageWarehouses', $branch);

        abort_if(
            $warehouse->is_default,
            403,
            'Gudang utama tidak dapat dihapus. Tunjuk gudang lain sebagai gudang utama terlebih dahulu.',
        );

        $warehouse->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Gudang {$warehouse->name} dihapus."]);

        return back();
    }
}
