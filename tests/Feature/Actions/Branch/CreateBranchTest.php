<?php

use App\Actions\Branch\CreateBranch;
use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\Warehouse;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function () {
    $this->action = app(CreateBranch::class);
});

function branchAttributes(array $overrides = []): array
{
    return [
        'code' => 'JKT-01',
        'name' => 'Cabang Jakarta Pusat',
        'address_line' => 'Jl. Thamrin No. 1',
        'status' => BranchStatus::Active,
        ...$overrides,
    ];
}

it('creates a default warehouse for the branch', function () {
    $branch = $this->action->handle(branchAttributes());

    $warehouse = $branch->warehouses()->sole();

    expect($warehouse->code)->toBe(CreateBranch::DEFAULT_WAREHOUSE_CODE)
        ->and($warehouse->is_default)->toBeTrue()
        ->and($warehouse->branch_id)->toBe($branch->id);
});

it('creates operating hours for every day of the week', function () {
    $branch = $this->action->handle(branchAttributes());

    expect($branch->operatingHours()->count())->toBe(7)
        ->and($branch->operatingHours()->where('day_of_week', 0)->value('is_closed'))->toBeTruthy();
});

it('derives a slug from the branch name', function () {
    $branch = $this->action->handle(branchAttributes());

    expect($branch->slug)->toBe('cabang-jakarta-pusat');
});

it('makes a slug unique when the name repeats', function () {
    $this->action->handle(branchAttributes());

    $second = $this->action->handle(branchAttributes(['code' => 'JKT-02']));

    expect($second->slug)->toBe('cabang-jakarta-pusat-2');
});

it('marks the first branch as the storefront default', function () {
    $branch = $this->action->handle(branchAttributes());

    expect($branch->is_default)->toBeTrue();
});

it('does not mark a later branch as the default', function () {
    $this->action->handle(branchAttributes());

    $second = $this->action->handle(branchAttributes(['code' => 'BDG-01', 'name' => 'Cabang Bandung']));

    expect($second->is_default)->toBeFalse();
});

it('rejects a duplicate branch code without leaving a partial branch behind', function () {
    $this->action->handle(branchAttributes());

    expect(fn () => $this->action->handle(branchAttributes(['name' => 'Cabang Lain'])))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(Branch::count())->toBe(1)
        ->and(Warehouse::count())->toBe(1);
});
