<?php

use App\Models\Branch;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;

/*
 * Warehouses per branch (P1-E2-T6).
 */

function warehousePayload(array $overrides = []): array
{
    return [
        'code' => 'WH-02',
        'name' => 'Gudang Cadangan',
        'address' => 'Jl. Gudang No. 2',
        'is_default' => false,
        'is_active' => true,
        ...$overrides,
    ];
}

it('adds a warehouse to a branch', function () {
    $branch = Branch::factory()->create();
    Warehouse::factory()->default()->for($branch)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.branches.warehouses.store', $branch), warehousePayload())
        ->assertRedirect();

    $warehouse = $branch->warehouses()->where('code', 'WH-02')->sole();

    expect($warehouse->name)->toBe('Gudang Cadangan')
        ->and($warehouse->is_default)->toBeFalse();
});

it('uppercases the warehouse code', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.branches.warehouses.store', $branch), warehousePayload(['code' => 'wh-02']));

    expect($branch->warehouses()->where('code', 'WH-02')->exists())->toBeTrue();
});

it('requires the warehouse code to be unique inside the branch only', function () {
    $branch = Branch::factory()->create();
    Warehouse::factory()->for($branch)->create(['code' => 'WH-02']);
    $otherBranch = Branch::factory()->create();
    $actor = User::factory()->adminPusat()->create();

    $this->actingAs($actor)
        ->post(route('admin.branches.warehouses.store', $branch), warehousePayload())
        ->assertSessionHasErrors('code');

    $this->actingAs($actor)
        ->post(route('admin.branches.warehouses.store', $otherBranch), warehousePayload())
        ->assertRedirect();

    expect($otherBranch->warehouses()->where('code', 'WH-02')->exists())->toBeTrue();
});

it('rejects a warehouse code with illegal characters', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.branches.warehouses.store', $branch), warehousePayload(['code' => 'wh 02!']))
        ->assertSessionHasErrors('code');
});

it('updates a warehouse', function () {
    $branch = Branch::factory()->create();
    $warehouse = Warehouse::factory()->for($branch)->create(['code' => 'WH-02']);
    Warehouse::factory()->default()->for($branch)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.warehouses.update', [$branch, $warehouse]), warehousePayload([
            'name' => 'Gudang Diubah',
            'is_active' => false,
        ]))
        ->assertRedirect();

    expect($warehouse->refresh())->name->toBe('Gudang Diubah')->is_active->toBeFalse();
});

it('hands the default flag to another warehouse', function () {
    $branch = Branch::factory()->create();
    $current = Warehouse::factory()->default()->for($branch)->create();
    $next = Warehouse::factory()->for($branch)->create(['code' => 'WH-02']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->patch(route('admin.branches.warehouses.default.update', [$branch, $next]))
        ->assertRedirect();

    expect($next->refresh()->is_default)->toBeTrue()
        ->and($current->refresh()->is_default)->toBeFalse();
});

it('refuses to drop the default flag without a replacement', function () {
    $branch = Branch::factory()->create();
    $warehouse = Warehouse::factory()->default()->for($branch)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.warehouses.update', [$branch, $warehouse]), warehousePayload([
            'code' => $warehouse->code,
            'is_default' => false,
        ]))
        ->assertSessionHasErrors('is_default');

    expect($warehouse->refresh()->is_default)->toBeTrue();
});

it('refuses to deactivate the default warehouse', function () {
    $branch = Branch::factory()->create();
    $warehouse = Warehouse::factory()->default()->for($branch)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.warehouses.update', [$branch, $warehouse]), warehousePayload([
            'code' => $warehouse->code,
            'is_default' => true,
            'is_active' => false,
        ]))
        ->assertSessionHasErrors('is_active');

    expect($warehouse->refresh()->is_active)->toBeTrue();
});

it('deletes a warehouse that is not the default', function () {
    $branch = Branch::factory()->create();
    Warehouse::factory()->default()->for($branch)->create();
    $warehouse = Warehouse::factory()->for($branch)->create(['code' => 'WH-02']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.branches.warehouses.destroy', [$branch, $warehouse]))
        ->assertRedirect();

    expect($branch->warehouses()->count())->toBe(1);
});

it('refuses to delete the default warehouse', function () {
    $branch = Branch::factory()->create();
    $warehouse = Warehouse::factory()->default()->for($branch)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.branches.warehouses.destroy', [$branch, $warehouse]))
        ->assertForbidden();

    expect($branch->warehouses()->count())->toBe(1);
});

it('refuses to delete a warehouse that still holds stock (P1-E4-T1)', function () {
    $branch = Branch::factory()->create();
    Warehouse::factory()->default()->for($branch)->create();
    $warehouse = Warehouse::factory()->for($branch)->create(['code' => 'WH-02']);
    Stock::factory()->inWarehouse($warehouse)->quantity(7)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.branches.warehouses.destroy', [$branch, $warehouse]))
        ->assertForbidden();

    expect($branch->warehouses()->count())->toBe(2);
});

it('refuses to delete an empty warehouse that still carries stock movements (P1-E4-T1)', function () {
    $branch = Branch::factory()->create();
    Warehouse::factory()->default()->for($branch)->create();
    $warehouse = Warehouse::factory()->for($branch)->create(['code' => 'WH-02']);
    Stock::factory()->inWarehouse($warehouse)->quantity(0)->create();
    StockMovement::factory()->inWarehouse($warehouse)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.branches.warehouses.destroy', [$branch, $warehouse]))
        ->assertForbidden();

    expect($branch->warehouses()->count())->toBe(2);
});

it('tells the branch screen why a warehouse cannot be deleted', function () {
    $branch = Branch::factory()->create();
    Warehouse::factory()->default()->for($branch)->create();
    $warehouse = Warehouse::factory()->for($branch)->create(['code' => 'WH-02']);
    Stock::factory()->inWarehouse($warehouse)->quantity(7)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.branches.edit', $branch))
        ->assertInertia(fn ($page) => $page
            ->where('warehouses.1.can_delete', false)
            ->where('warehouses.1.on_hand', 7)
            ->where('warehouses.0.can_delete', false));
});

it('404s on a warehouse that belongs to another branch', function () {
    $branch = Branch::factory()->create();
    $foreign = Warehouse::factory()->create(['code' => 'WH-09']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.branches.warehouses.destroy', [$branch, $foreign]))
        ->assertNotFound();

    expect($foreign->refresh()->exists)->toBeTrue();
});

it('lets a branch admin manage the warehouses of their own branch only', function () {
    $branch = Branch::factory()->create();
    $other = Branch::factory()->create();
    $actor = User::factory()->adminCabang($branch)->create();

    $this->actingAs($actor)
        ->post(route('admin.branches.warehouses.store', $branch), warehousePayload())
        ->assertRedirect();

    $this->actingAs($actor)
        ->post(route('admin.branches.warehouses.store', $other), warehousePayload())
        ->assertForbidden();

    expect($branch->warehouses()->count())->toBe(1)
        ->and($other->warehouses()->count())->toBe(0);
});

it('forbids a warehouse staffer, who may only read warehouses', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->post(route('admin.branches.warehouses.store', $branch), warehousePayload())
        ->assertForbidden();
});

it('records warehouse changes in the audit log', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.branches.warehouses.store', $branch), warehousePayload());

    $warehouse = $branch->warehouses()->sole();

    expect($warehouse->auditLogs()->where('event', 'created')->exists())->toBeTrue();
});
