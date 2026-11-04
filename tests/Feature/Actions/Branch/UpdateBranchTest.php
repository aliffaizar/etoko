<?php

use App\Actions\Branch\CreateBranch;
use App\Actions\Branch\UpdateBranch;
use App\Enums\BranchStatus;
use App\Models\Branch;

beforeEach(function () {
    $this->action = app(UpdateBranch::class);
});

it('saves the branch details it is given', function () {
    $branch = Branch::factory()->create(['name' => 'Cabang Lama', 'city_name' => 'Bandung']);

    $updated = $this->action->handle($branch, [
        'name' => 'Cabang Baru',
        'city_name' => 'Surabaya',
        'status' => BranchStatus::Maintenance,
    ]);

    expect($updated->refresh())
        ->name->toBe('Cabang Baru')
        ->city_name->toBe('Surabaya')
        ->status->toBe(BranchStatus::Maintenance);
});

it('moves the storefront default flag off the previous holder', function () {
    $current = Branch::factory()->default()->create();
    $next = Branch::factory()->create();

    $this->action->handle($next, ['is_default' => true]);

    expect($next->refresh()->is_default)->toBeTrue()
        ->and($current->refresh()->is_default)->toBeFalse();
});

it('leaves exactly one default branch behind however often it is promoted', function () {
    Branch::factory()->default()->create();
    $branches = Branch::factory()->count(3)->create();

    foreach ($branches as $branch) {
        $this->action->handle($branch, ['is_default' => true]);
    }

    expect(Branch::where('is_default', true)->count())->toBe(1)
        ->and(Branch::where('is_default', true)->value('id'))->toBe($branches->last()->id);
});

it('does not touch other branches when the branch is not being promoted', function () {
    $default = Branch::factory()->default()->create();
    $other = Branch::factory()->create();

    $this->action->handle($other, ['name' => 'Nama Baru']);

    expect($default->refresh()->is_default)->toBeTrue();
});

it('keeps warehouses and operating hours untouched', function () {
    $branch = app(CreateBranch::class)->handle([
        'code' => 'JKT-09',
        'name' => 'Cabang Sembilan',
        'address_line' => 'Jl. Sudirman 9',
        'status' => BranchStatus::Active,
    ]);

    $this->action->handle($branch, ['name' => 'Cabang Sembilan Baru']);

    expect($branch->warehouses()->count())->toBe(1)
        ->and($branch->operatingHours()->count())->toBe(7);
});
