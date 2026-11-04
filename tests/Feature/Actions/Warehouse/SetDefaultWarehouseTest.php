<?php

use App\Actions\Warehouse\CreateWarehouse;
use App\Actions\Warehouse\SetDefaultWarehouse;
use App\Models\Branch;
use App\Models\Warehouse;

it('demotes the branch default and promotes the given warehouse', function () {
    $branch = Branch::factory()->create();
    $current = Warehouse::factory()->default()->for($branch)->create();
    $next = Warehouse::factory()->for($branch)->create(['code' => 'WH-02']);

    app(SetDefaultWarehouse::class)->handle($next);

    expect($next->refresh()->is_default)->toBeTrue()
        ->and($current->refresh()->is_default)->toBeFalse();
});

it('leaves the default warehouse of another branch alone', function () {
    $other = Warehouse::factory()->default()->create();
    $branch = Branch::factory()->create();
    $warehouse = Warehouse::factory()->for($branch)->create();

    app(SetDefaultWarehouse::class)->handle($warehouse);

    expect($other->refresh()->is_default)->toBeTrue();
});

it('reactivates a warehouse it promotes, because stock has to be able to move', function () {
    $branch = Branch::factory()->create();
    Warehouse::factory()->default()->for($branch)->create();
    $warehouse = Warehouse::factory()->inactive()->for($branch)->create(['code' => 'WH-03']);

    app(SetDefaultWarehouse::class)->handle($warehouse);

    expect($warehouse->refresh())->is_default->toBeTrue()->is_active->toBeTrue();
});

it('makes the first warehouse of a branch the default even when the form did not ask', function () {
    $branch = Branch::factory()->create();

    $warehouse = app(CreateWarehouse::class)->handle($branch, [
        'code' => 'WH-01',
        'name' => 'Gudang Utama',
        'is_default' => false,
        'is_active' => false,
    ]);

    expect($warehouse->refresh())->is_default->toBeTrue()->is_active->toBeTrue();
});

it('adds a later warehouse without disturbing the default', function () {
    $branch = Branch::factory()->create();
    $first = Warehouse::factory()->default()->for($branch)->create();

    $second = app(CreateWarehouse::class)->handle($branch, ['code' => 'WH-02', 'name' => 'Gudang Dua']);

    expect($second->is_default)->toBeFalse()
        ->and($first->refresh()->is_default)->toBeTrue();
});
