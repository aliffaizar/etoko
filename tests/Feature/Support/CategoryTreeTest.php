<?php

use App\Models\Category;
use App\Support\CategoryTree;

/*
 * CategoryTree (P1-E3-T2) and the tree helpers on Category.
 */

it('orders parents before their children, each level by sort order', function () {
    $fesyen = Category::factory()->create(['name' => 'Fesyen', 'sort_order' => 2]);
    $elektronik = Category::factory()->create(['name' => 'Elektronik', 'sort_order' => 1]);
    $audio = Category::factory()->childOf($elektronik)->create(['name' => 'Audio', 'sort_order' => 2]);
    $laptop = Category::factory()->childOf($elektronik)->create(['name' => 'Laptop', 'sort_order' => 1]);

    $rows = CategoryTree::of(Category::query()->get())->flatten();

    expect(array_map(fn (array $row): int => $row['category']->id, $rows))
        ->toBe([$elektronik->id, $laptop->id, $audio->id, $fesyen->id]);
});

it('falls back to the name when two categories share a sort order', function () {
    $zebra = Category::factory()->create(['name' => 'Zebra', 'sort_order' => 0]);
    $apel = Category::factory()->create(['name' => 'Apel', 'sort_order' => 0]);

    $rows = CategoryTree::of(Category::query()->get())->flatten();

    expect(array_map(fn (array $row): int => $row['category']->id, $rows))
        ->toBe([$apel->id, $zebra->id]);
});

it('reports the depth and ancestor names of each row', function () {
    $root = Category::factory()->create(['name' => 'Elektronik']);
    $child = Category::factory()->childOf($root)->create(['name' => 'Audio']);
    Category::factory()->childOf($child)->create(['name' => 'Headphone']);

    $rows = CategoryTree::of(Category::query()->get())->flatten();

    expect($rows[0]['depth'])->toBe(1)
        ->and($rows[0]['path'])->toBe([])
        ->and($rows[1]['depth'])->toBe(2)
        ->and($rows[1]['path'])->toBe(['Elektronik'])
        ->and($rows[2]['depth'])->toBe(3)
        ->and($rows[2]['path'])->toBe(['Elektronik', 'Audio']);
});

it('treats a category whose parent is missing from the set as a root', function () {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();

    // The parent is deliberately left out, as a filtered listing would.
    $rows = CategoryTree::of(Category::query()->whereKey($child->id)->get())->flatten();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['category']->id)->toBe($child->id)
        ->and($rows[0]['depth'])->toBe(1);
});

it('reports a category depth counting from one', function () {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();
    $grandchild = Category::factory()->childOf($child)->create();

    expect($root->depth())->toBe(1)
        ->and($child->depth())->toBe(2)
        ->and($grandchild->depth())->toBe(3);
});

it('reports a depth even when an ancestor has been soft deleted', function () {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();
    $root->delete();

    expect($child->depth())->toBe(2);
});

it('reports how tall a subtree is', function () {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();
    Category::factory()->childOf($child)->create();

    expect($root->subtreeHeight())->toBe(3)
        ->and($child->subtreeHeight())->toBe(2)
        ->and(Category::factory()->create()->subtreeHeight())->toBe(1);
});

it('collects every descendant id', function () {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();
    $grandchild = Category::factory()->childOf($child)->create();
    $other = Category::factory()->create();

    expect($root->descendantIds())->toEqualCanonicalizing([$child->id, $grandchild->id])
        ->and($other->descendantIds())->toBe([]);
});
