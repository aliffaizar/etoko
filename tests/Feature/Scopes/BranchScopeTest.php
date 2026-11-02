<?php

use App\Models\Branch;
use App\Models\Warehouse;
use App\Services\Branch\BranchContext;

beforeEach(function () {
    $this->context = app(BranchContext::class);
});

it('returns warehouses from every branch when the context is unrestricted', function () {
    Warehouse::factory()->count(2)->create();

    expect(Warehouse::count())->toBe(2);
});

it('returns only warehouses of the restricted branch', function () {
    $branch = Branch::factory()->create();
    Warehouse::factory()->for($branch)->create();
    Warehouse::factory()->create();

    $this->context->restrictTo($branch->id);

    expect(Warehouse::pluck('branch_id')->all())->toBe([$branch->id]);
});

it('hides a warehouse of another branch from find', function () {
    $otherBranchWarehouse = Warehouse::factory()->create();
    $branch = Branch::factory()->create();

    $this->context->restrictTo($branch->id);

    expect(Warehouse::find($otherBranchWarehouse->id))->toBeNull();
});

it('returns warehouses of every branch through acrossBranches', function () {
    $branch = Branch::factory()->create();
    Warehouse::factory()->for($branch)->create();
    Warehouse::factory()->create();

    $this->context->restrictTo($branch->id);

    expect(Warehouse::acrossBranches()->count())->toBe(2);
});

it('does not restrict queries on a model without the branch trait', function () {
    Branch::factory()->count(3)->create();

    $this->context->restrictTo(Branch::first()->id);

    expect(Branch::count())->toBe(3);
});
