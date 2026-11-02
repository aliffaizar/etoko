<?php

use App\Services\Branch\BranchContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

it('starts unrestricted', function () {
    $context = new BranchContext;

    expect($context->isRestricted())->toBeFalse()
        ->and($context->branchId())->toBeNull();
});

it('restricts to a branch', function () {
    $context = new BranchContext;

    $context->restrictTo(7);

    expect($context->isRestricted())->toBeTrue()
        ->and($context->branchId())->toBe(7);
});

it('clears an existing restriction', function () {
    $context = new BranchContext;
    $context->restrictTo(7);

    $context->clearRestriction();

    expect($context->isRestricted())->toBeFalse();
});

it('lifts the restriction only for the duration of the callback', function () {
    $context = new BranchContext;
    $context->restrictTo(7);

    $insideCallback = $context->withoutRestriction(fn (): ?int => $context->branchId());

    expect($insideCallback)->toBeNull()
        ->and($context->branchId())->toBe(7);
});

it('restores the previous branch when the callback throws', function () {
    $context = new BranchContext;
    $context->restrictTo(7);

    expect(fn () => $context->withoutRestriction(function (): never {
        throw new ModelNotFoundException;
    }))->toThrow(ModelNotFoundException::class);

    expect($context->branchId())->toBe(7);
});

it('switches to another branch only for the duration of the callback', function () {
    $context = new BranchContext;
    $context->restrictTo(7);

    $insideCallback = $context->forBranch(9, fn (): ?int => $context->branchId());

    expect($insideCallback)->toBe(9)
        ->and($context->branchId())->toBe(7);
});
