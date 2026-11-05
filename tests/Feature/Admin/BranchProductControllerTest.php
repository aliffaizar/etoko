<?php

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;

/*
 * Per-branch product availability (P1-E3-T9).
 *
 * The rule that shapes every test here: no row means available. The table
 * holds the exceptions, so switching a product back on deletes its row rather
 * than writing `is_available = true`.
 */

function availabilityOf(Branch $branch, Product $product): bool
{
    return BranchProduct::availabilityFor(
        BranchProduct::acrossBranches()
            ->where('branch_id', $branch->getKey())
            ->where('product_id', $product->getKey())
            ->first(),
    );
}

it('redirects guests to the login page', function () {
    $branch = Branch::factory()->create();

    $this->get(route('admin.branches.products.index', $branch))->assertRedirect(route('login'));
});

it('lists the whole catalogue as available before the branch says anything', function () {
    $branch = Branch::factory()->create();
    Product::factory()->count(3)->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.branches.products.index', $branch))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/branches/products')
            ->has('products.data', 3)
            ->where('products.data.0.is_available', true)
            ->where('products.data.0.has_setting', false)
            ->where('summary.unavailable', 0)
            ->where('summary.available', 3));
});

it('shows a product the branch has switched off', function () {
    $branch = Branch::factory()->create();
    $product = Product::factory()->withDefaultVariant()->create();
    BranchProduct::factory()->for($branch)->for($product)->withNotes('Tidak ada permintaan')->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.branches.products.index', $branch))
        ->assertInertia(fn ($page) => $page
            ->where('products.data.0.is_available', false)
            ->where('products.data.0.has_setting', true)
            ->where('products.data.0.notes', 'Tidak ada permintaan')
            ->where('summary.unavailable', 1));
});

it('lets a branch admin read their own branch', function () {
    $branch = Branch::factory()->create();
    Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->get(route('admin.branches.products.index', $branch))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.manage', false));
});

it('refuses a branch admin reading another branch', function () {
    $own = Branch::factory()->create();
    $other = Branch::factory()->create();

    $this->actingAs(User::factory()->adminCabang($own)->create())
        ->get(route('admin.branches.products.index', $other))
        ->assertForbidden();
});

it('forbids customers', function () {
    $this->actingAs(User::factory()->customer()->create())
        ->get(route('admin.branches.products.index', Branch::factory()->create()))
        ->assertForbidden();
});

it('offers managing only to central staff', function (UserRole $role, bool $expected) {
    $branch = Branch::factory()->create();

    $this->actingAs(User::factory()->withRole($role)->forBranch($branch)->create())
        ->get(route('admin.branches.products.index', $branch))
        ->assertInertia(fn ($page) => $page->where('can.manage', $expected));
})->with([
    'super admin' => [UserRole::SuperAdmin, true],
    'admin pusat' => [UserRole::AdminPusat, true],
    'admin cabang' => [UserRole::AdminCabang, false],
    'staff gudang' => [UserRole::StaffGudang, false],
    'kasir' => [UserRole::Kasir, false],
]);

it('switches one product off for the branch', function () {
    $branch = Branch::factory()->create();
    $product = Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.products.update', $branch), [
            'product_ids' => [$product->id],
            'is_available' => false,
        ])
        ->assertRedirect();

    expect(availabilityOf($branch, $product))->toBeFalse();
});

it('switches many products off at once', function () {
    $branch = Branch::factory()->create();
    $products = Product::factory()->count(3)->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.products.update', $branch), [
            'product_ids' => $products->pluck('id')->all(),
            'is_available' => false,
        ])
        ->assertRedirect();

    expect(BranchProduct::acrossBranches()->where('branch_id', $branch->id)->unavailable()->count())->toBe(3);
});

it('deletes the row instead of storing the default when a product is switched back on', function () {
    $branch = Branch::factory()->create();
    $product = Product::factory()->withDefaultVariant()->create();
    BranchProduct::factory()->for($branch)->for($product)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.products.update', $branch), [
            'product_ids' => [$product->id],
            'is_available' => true,
        ])
        ->assertRedirect();

    expect(BranchProduct::acrossBranches()->where('branch_id', $branch->id)->count())->toBe(0)
        ->and(availabilityOf($branch, $product))->toBeTrue();
});

it('keeps a row for an available product when a note was written', function () {
    $branch = Branch::factory()->create();
    $product = Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.products.update', $branch), [
            'product_ids' => [$product->id],
            'is_available' => true,
            'notes' => 'Stok terbatas, jual sesuai permintaan',
        ]);

    $row = BranchProduct::acrossBranches()->where('branch_id', $branch->id)->sole();

    expect($row->is_available)->toBeTrue()
        ->and($row->notes)->toBe('Stok terbatas, jual sesuai permintaan');
});

it('reports when nothing changed', function () {
    $branch = Branch::factory()->create();
    $product = Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.products.update', $branch), [
            'product_ids' => [$product->id],
            'is_available' => true,
        ]);

    expect(BranchProduct::acrossBranches()->count())->toBe(0);
});

it('stores one row per branch and product however often it is set', function () {
    $branch = Branch::factory()->create();
    $product = Product::factory()->withDefaultVariant()->create();
    $actor = User::factory()->adminPusat()->create();

    foreach ([false, false, false] as $available) {
        $this->actingAs($actor)->put(route('admin.branches.products.update', $branch), [
            'product_ids' => [$product->id],
            'is_available' => $available,
        ]);
    }

    expect(BranchProduct::acrossBranches()->where('branch_id', $branch->id)->count())->toBe(1);
});

it('leaves other branches alone', function () {
    $branch = Branch::factory()->create();
    $other = Branch::factory()->create();
    $product = Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.products.update', $branch), [
            'product_ids' => [$product->id],
            'is_available' => false,
        ]);

    expect(availabilityOf($other, $product))->toBeTrue();
});

it('refuses a write from a branch admin, even for their own branch', function () {
    $branch = Branch::factory()->create();
    $product = Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->put(route('admin.branches.products.update', $branch), [
            'product_ids' => [$product->id],
            'is_available' => false,
        ])
        ->assertForbidden();

    expect(availabilityOf($branch, $product))->toBeTrue();
});

it('refuses more products than one call may change', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.products.update', $branch), [
            'product_ids' => range(1, 201),
            'is_available' => false,
        ])
        ->assertSessionHasErrors('product_ids');
});

it('refuses a product id that does not exist', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.products.update', $branch), [
            'product_ids' => [999_999],
            'is_available' => false,
        ])
        ->assertSessionHasErrors('product_ids.0');
});

it('filters to the products this branch does not sell', function () {
    $branch = Branch::factory()->create();
    $off = Product::factory()->withDefaultVariant()->create();
    Product::factory()->count(2)->withDefaultVariant()->create();
    BranchProduct::factory()->for($branch)->for($off)->create();

    $actor = User::factory()->adminPusat()->create();

    $this->actingAs($actor)
        ->get(route('admin.branches.products.index', [$branch, 'availability' => 'unavailable']))
        ->assertInertia(fn ($page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.id', $off->id));

    $this->actingAs($actor)
        ->get(route('admin.branches.products.index', [$branch, 'availability' => 'available']))
        ->assertInertia(fn ($page) => $page->has('products.data', 2));
});

it('filters by category including its subcategories', function () {
    $branch = Branch::factory()->create();
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();

    Product::factory()->for($root)->withDefaultVariant()->create();
    Product::factory()->for($child)->withDefaultVariant()->create();
    Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.branches.products.index', [$branch, 'category' => $root->id]))
        ->assertInertia(fn ($page) => $page->has('products.data', 2));
});

it('searches by product name and sku', function () {
    $branch = Branch::factory()->create();
    Product::factory()->withDefaultVariant()->create(['name' => 'Kipas Angin Berdiri']);
    Product::factory()->withDefaultVariant()->create(['name' => 'Setrika Uap']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.branches.products.index', [$branch, 'search' => 'Kipas']))
        ->assertInertia(fn ($page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Kipas Angin Berdiri'));
});

it('refuses a sort column that is not whitelisted', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.branches.products.index', [$branch, 'sort' => 'cost_price']))
        ->assertSessionHasErrors('sort');
});

it('drops a branch availability row with the branch', function () {
    $branch = Branch::factory()->create();
    $product = Product::factory()->withDefaultVariant()->create();
    BranchProduct::factory()->for($branch)->for($product)->create();

    $branch->forceDelete();

    expect(BranchProduct::acrossBranches()->count())->toBe(0);
});
