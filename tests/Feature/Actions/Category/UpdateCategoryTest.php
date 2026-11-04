<?php

use App\Actions\Category\UpdateCategory;
use App\Models\Category;

/*
 * UpdateCategory (P1-E3-T2).
 *
 * ADR-019: an active category always has active ancestors. Deactivating one
 * therefore takes its whole subtree with it, which is this action's job; the
 * other half of the invariant lives in UpdateCategoryRequest.
 */

it('updates the category it is given', function () {
    $category = Category::factory()->create(['name' => 'Elektronik', 'sort_order' => 0]);

    app(UpdateCategory::class)->handle($category, ['name' => 'Elektronik & Gadget', 'sort_order' => 4]);

    expect($category->refresh()->name)->toBe('Elektronik & Gadget')
        ->and($category->sort_order)->toBe(4);
});

it('deactivates every level below the category', function () {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();
    $grandchild = Category::factory()->childOf($child)->create();

    app(UpdateCategory::class)->handle($root, ['is_active' => false]);

    expect($root->refresh()->is_active)->toBeFalse()
        ->and($child->refresh()->is_active)->toBeFalse()
        ->and($grandchild->refresh()->is_active)->toBeFalse();
});

it('leaves categories outside the subtree alone', function () {
    $root = Category::factory()->create();
    Category::factory()->childOf($root)->create();
    $sibling = Category::factory()->create();

    app(UpdateCategory::class)->handle($root, ['is_active' => false]);

    expect($sibling->refresh()->is_active)->toBeTrue();
});

it('does not cascade when the category was already inactive', function () {
    $root = Category::factory()->inactive()->create();
    $child = Category::factory()->childOf($root)->create();

    app(UpdateCategory::class)->handle($root, ['is_active' => false, 'name' => 'Arsip']);

    expect($root->refresh()->name)->toBe('Arsip')
        ->and($child->refresh()->is_active)->toBeTrue();
});

it('does not cascade when the category is being activated', function () {
    $root = Category::factory()->inactive()->create();
    $child = Category::factory()->inactive()->childOf($root)->create();

    app(UpdateCategory::class)->handle($root, ['is_active' => true]);

    expect($root->refresh()->is_active)->toBeTrue()
        ->and($child->refresh()->is_active)->toBeFalse();
});

it('records each hidden subcategory in the audit log', function () {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();

    app(UpdateCategory::class)->handle($root, ['is_active' => false]);

    expect($child->auditLogs()->where('event', 'updated')->exists())->toBeTrue();
});
