<?php

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\User;

/*
 * Category management (P1-E3-T2).
 *
 * These tests run against the real permission baseline from config/rbac.php, so
 * a change there that widens access shows up here.
 */

function categoryPayload(array $overrides = []): array
{
    return [
        'parent_id' => null,
        'name' => 'Alat Dapur',
        'description' => 'Peralatan memasak dan menyajikan.',
        'icon' => 'utensils',
        'is_active' => true,
        'sort_order' => 2,
        ...$overrides,
    ];
}

it('redirects guests to the login page', function () {
    $this->get(route('admin.categories.index'))->assertRedirect(route('login'));
});

it('lists categories for central staff', function (UserRole $role) {
    Category::factory()->count(2)->create();

    $this->actingAs(User::factory()->withRole($role)->create())
        ->get(route('admin.categories.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/categories/index')
            ->has('categories', 2)
            ->has('filters')
            ->where('can.create', true));
})->with([
    'super admin' => [UserRole::SuperAdmin],
    'admin pusat' => [UserRole::AdminPusat],
]);

it('lets a branch admin read the tree but not change it', function () {
    Category::factory()->create();

    $this->actingAs(User::factory()->adminCabang()->create())
        ->get(route('admin.categories.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('categories', 1)
            ->where('can.create', false)
            ->where('categories.0.can.update', false)
            ->where('categories.0.can.delete', false));
});

it('forbids roles without the categories.view permission', function (UserRole $role) {
    $this->actingAs(User::factory()->withRole($role)->forBranch()->create())
        ->get(route('admin.categories.index'))
        ->assertForbidden();
})->with([
    'staff gudang' => [UserRole::StaffGudang],
]);

it('forbids customers', function () {
    $this->actingAs(User::factory()->customer()->create())
        ->get(route('admin.categories.index'))
        ->assertForbidden();
});

it('lists the tree with parents before their children', function () {
    $root = Category::factory()->create(['name' => 'Elektronik', 'sort_order' => 1]);
    $child = Category::factory()->childOf($root)->create(['name' => 'Audio']);
    $grandchild = Category::factory()->childOf($child)->create(['name' => 'Headphone']);
    $otherRoot = Category::factory()->create(['name' => 'Fesyen', 'sort_order' => 2]);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.categories.index'))
        ->assertInertia(fn ($page) => $page
            ->has('categories', 4)
            ->where('categories.0.id', $root->id)
            ->where('categories.0.depth', 1)
            ->where('categories.1.id', $child->id)
            ->where('categories.1.depth', 2)
            ->where('categories.2.id', $grandchild->id)
            ->where('categories.2.depth', 3)
            ->where('categories.2.path', ['Elektronik', 'Audio'])
            ->where('categories.3.id', $otherRoot->id));
});

it('counts subcategories per row', function () {
    $root = Category::factory()->create();
    Category::factory()->count(3)->childOf($root)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.categories.index'))
        ->assertInertia(fn ($page) => $page->where('categories.0.child_count', 3));
});

it('filters the listing by status and search', function () {
    $actor = User::factory()->adminPusat()->create();

    Category::factory()->create(['name' => 'Elektronik', 'slug' => 'elektronik']);
    Category::factory()->inactive()->create(['name' => 'Arsip Lama', 'slug' => 'arsip-lama']);

    $this->actingAs($actor)
        ->get(route('admin.categories.index', ['status' => 'inactive']))
        ->assertInertia(fn ($page) => $page
            ->has('categories', 1)
            ->where('categories.0.name', 'Arsip Lama'));

    $this->actingAs($actor)
        ->get(route('admin.categories.index', ['search' => 'elektro']))
        ->assertInertia(fn ($page) => $page
            ->has('categories', 1)
            ->where('categories.0.name', 'Elektronik'));
});

it('finds a nested category by search even when its ancestors do not match', function () {
    $root = Category::factory()->create(['name' => 'Elektronik']);
    $child = Category::factory()->childOf($root)->create(['name' => 'Headphone']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.categories.index', ['search' => 'headphone']))
        ->assertInertia(fn ($page) => $page
            ->has('categories', 1)
            ->where('categories.0.id', $child->id));
});

it('rejects a sort column that is not whitelisted', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.categories.index', ['sort' => 'id', 'search' => 'a']))
        ->assertSessionHasErrors('sort');
});

it('creates a root category and derives its slug', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.categories.store'), categoryPayload())
        ->assertRedirect(route('admin.categories.edit', Category::firstOrFail()));

    $category = Category::firstOrFail();

    expect($category->name)->toBe('Alat Dapur')
        ->and($category->slug)->toBe('alat-dapur')
        ->and($category->parent_id)->toBeNull()
        ->and($category->sort_order)->toBe(2)
        ->and($category->is_active)->toBeTrue();
});

it('gives a second category with the same name a distinct slug', function () {
    $actor = User::factory()->adminPusat()->create();

    $this->actingAs($actor)->post(route('admin.categories.store'), categoryPayload());
    $this->actingAs($actor)->post(route('admin.categories.store'), categoryPayload());

    expect(Category::pluck('slug')->all())->toBe(['alat-dapur', 'alat-dapur-2']);
});

it('creates a category under a parent', function () {
    $parent = Category::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.categories.store'), categoryPayload(['parent_id' => $parent->id]))
        ->assertSessionHasNoErrors();

    expect(Category::where('parent_id', $parent->id)->exists())->toBeTrue();
});

it('refuses a fourth level of nesting', function () {
    $level3 = Category::factory()
        ->childOf(Category::factory()->childOf(Category::factory()->create())->create())
        ->create();

    expect($level3->depth())->toBe(3);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.categories.store'), categoryPayload(['parent_id' => $level3->id]))
        ->assertSessionHasErrors('parent_id');

    expect(Category::where('parent_id', $level3->id)->exists())->toBeFalse();
});

it('refuses creating an active category under an inactive parent', function () {
    $parent = Category::factory()->inactive()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.categories.store'), categoryPayload(['parent_id' => $parent->id, 'is_active' => true]))
        ->assertSessionHasErrors('is_active');
});

it('allows an inactive category under an inactive parent', function () {
    $parent = Category::factory()->inactive()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.categories.store'), categoryPayload(['parent_id' => $parent->id, 'is_active' => false]))
        ->assertSessionHasNoErrors();
});

it('forbids a branch admin from creating a category', function () {
    $this->actingAs(User::factory()->adminCabang()->create())
        ->post(route('admin.categories.store'), categoryPayload())
        ->assertForbidden();

    expect(Category::count())->toBe(0);
});

it('shows the edit screen with the parents a category may move to', function () {
    $root = Category::factory()->create(['name' => 'Elektronik']);
    $child = Category::factory()->childOf($root)->create(['name' => 'Audio']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.categories.edit', $child))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/categories/edit')
            ->where('category.id', $child->id)
            ->where('category.depth', 2)
            ->has('parentOptions', 1)
            ->where('parentOptions.0.value', $root->id));
});

it('leaves a category and its own subtree out of its parent options', function () {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.categories.edit', $root))
        ->assertInertia(fn ($page) => $page->has('parentOptions', 0));

    expect(Category::whereKey([$root->id, $child->id])->count())->toBe(2);
});

it('updates a category', function () {
    $category = Category::factory()->create(['name' => 'Elektronik', 'slug' => 'elektronik']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.categories.update', $category), categoryPayload([
            'slug' => 'alat-dapur',
            'sort_order' => 5,
        ]))
        ->assertRedirect(route('admin.categories.edit', $category))
        ->assertSessionHasNoErrors();

    expect($category->refresh()->name)->toBe('Alat Dapur')
        ->and($category->slug)->toBe('alat-dapur')
        ->and($category->sort_order)->toBe(5);
});

it('refuses a slug that another category already holds', function () {
    Category::factory()->create(['slug' => 'elektronik']);
    $category = Category::factory()->create(['slug' => 'fesyen']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.categories.update', $category), categoryPayload(['slug' => 'elektronik']))
        ->assertSessionHasErrors('slug');
});

it('lets a category keep its own slug', function () {
    $category = Category::factory()->create(['slug' => 'elektronik']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.categories.update', $category), categoryPayload(['slug' => 'elektronik']))
        ->assertSessionHasNoErrors();
});

it('refuses a slug that is not url safe', function () {
    $category = Category::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.categories.update', $category), categoryPayload(['slug' => 'Alat Dapur!']))
        ->assertSessionHasErrors('slug');
});

it('refuses making a category its own parent', function () {
    $category = Category::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.categories.update', $category), categoryPayload([
            'slug' => 'alat-dapur',
            'parent_id' => $category->id,
        ]))
        ->assertSessionHasErrors('parent_id');
});

it('refuses moving a category under its own descendant', function () {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.categories.update', $root), categoryPayload([
            'slug' => 'alat-dapur',
            'parent_id' => $child->id,
        ]))
        ->assertSessionHasErrors('parent_id');

    expect($root->refresh()->parent_id)->toBeNull();
});

it('refuses a move that would push the subtree past three levels', function () {
    $movingRoot = Category::factory()->create();
    Category::factory()->childOf($movingRoot)->create();

    $target = Category::factory()->childOf(Category::factory()->create())->create();

    expect($movingRoot->subtreeHeight())->toBe(2)
        ->and($target->depth())->toBe(2);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.categories.update', $movingRoot), categoryPayload([
            'slug' => 'alat-dapur',
            'parent_id' => $target->id,
        ]))
        ->assertSessionHasErrors('parent_id');
});

it('allows a move that fits inside three levels', function () {
    $moving = Category::factory()->create();
    $target = Category::factory()->childOf(Category::factory()->create())->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.categories.update', $moving), categoryPayload([
            'slug' => 'alat-dapur',
            'parent_id' => $target->id,
        ]))
        ->assertSessionHasNoErrors();

    expect($moving->refresh()->parent_id)->toBe($target->id);
});

it('forbids a branch admin from updating a category', function () {
    $category = Category::factory()->create(['name' => 'Elektronik']);

    $this->actingAs(User::factory()->adminCabang()->create())
        ->put(route('admin.categories.update', $category), categoryPayload(['slug' => 'alat-dapur']))
        ->assertForbidden();

    expect($category->refresh()->name)->toBe('Elektronik');
});

it('deactivates a category and its whole subtree', function () {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();
    $grandchild = Category::factory()->childOf($child)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->patch(route('admin.categories.status.update', $root), ['is_active' => false])
        ->assertRedirect();

    expect($root->refresh()->is_active)->toBeFalse()
        ->and($child->refresh()->is_active)->toBeFalse()
        ->and($grandchild->refresh()->is_active)->toBeFalse();
});

it('does not reactivate the subtree when the parent is activated again', function () {
    $root = Category::factory()->inactive()->create();
    $child = Category::factory()->inactive()->childOf($root)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->patch(route('admin.categories.status.update', $root), ['is_active' => true])
        ->assertSessionHasNoErrors();

    expect($root->refresh()->is_active)->toBeTrue()
        ->and($child->refresh()->is_active)->toBeFalse();
});

it('refuses activating a category while its parent is inactive', function () {
    $parent = Category::factory()->inactive()->create();
    $child = Category::factory()->inactive()->childOf($parent)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->patch(route('admin.categories.status.update', $child), ['is_active' => true])
        ->assertSessionHasErrors('is_active');

    expect($child->refresh()->is_active)->toBeFalse();
});

it('soft deletes a leaf category', function () {
    $category = Category::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.categories.destroy', $category))
        ->assertRedirect(route('admin.categories.index'));

    expect($category->refresh()->trashed())->toBeTrue();
});

it('refuses deleting a category that still has subcategories', function () {
    $root = Category::factory()->create();
    Category::factory()->childOf($root)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.categories.destroy', $root))
        ->assertForbidden();

    expect($root->refresh()->trashed())->toBeFalse();
});

it('tells the listing why a category cannot be deleted', function () {
    $root = Category::factory()->create();
    Category::factory()->childOf($root)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.categories.index'))
        ->assertInertia(fn ($page) => $page
            ->where('categories.0.can.delete', false)
            ->where('categories.0.delete_blocked_reason', fn ($reason) => str_contains((string) $reason, 'subkategori')));
});

it('forbids a branch admin from deleting a category', function () {
    $category = Category::factory()->create();

    $this->actingAs(User::factory()->adminCabang()->create())
        ->delete(route('admin.categories.destroy', $category))
        ->assertForbidden();

    expect($category->refresh()->trashed())->toBeFalse();
});

it('records category changes in the audit log', function () {
    $category = Category::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.categories.update', $category), categoryPayload(['slug' => 'alat-dapur']));

    expect($category->auditLogs()->where('event', 'updated')->exists())->toBeTrue();
});
