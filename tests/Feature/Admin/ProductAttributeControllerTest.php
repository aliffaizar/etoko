<?php

use App\Enums\ProductAttributeType;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductVariant;
use App\Models\User;

/*
 * Product attributes and their values (P1-E3-T6, ADR-021).
 *
 * Attributes are catalogue-wide master data, so writes demand a central actor
 * (BR-1) and an attribute still defining variants cannot be deleted.
 */

it('redirects guests to the login page', function () {
    $this->get(route('admin.product-attributes.index'))->assertRedirect(route('login'));
});

it('lists attributes for every role holding products.view', function (UserRole $role) {
    ProductAttribute::factory()->withValues(['Merah', 'Biru'])->create();

    $this->actingAs(User::factory()->withRole($role)->forBranch()->create())
        ->get(route('admin.product-attributes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/product-attributes/index')
            ->has('attributes', 1)
            ->has('attributes.0.values', 2));
})->with([
    'super admin' => [UserRole::SuperAdmin],
    'admin pusat' => [UserRole::AdminPusat],
    'admin cabang' => [UserRole::AdminCabang],
    'staff gudang' => [UserRole::StaffGudang],
    'kasir' => [UserRole::Kasir],
]);

it('forbids customers', function () {
    $this->actingAs(User::factory()->customer()->create())
        ->get(route('admin.product-attributes.index'))
        ->assertForbidden();
});

it('only offers writing to central staff', function () {
    ProductAttribute::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.product-attributes.index'))
        ->assertInertia(fn ($page) => $page
            ->where('can.create', true)
            ->where('attributes.0.can.update', true));

    $this->actingAs(User::factory()->adminCabang()->create())
        ->get(route('admin.product-attributes.index'))
        ->assertInertia(fn ($page) => $page
            ->where('can.create', false)
            ->where('attributes.0.can.update', false));
});

it('creates an attribute and derives its slug', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.product-attributes.store'), [
            'name' => 'Ukuran Sepatu',
            'type' => ProductAttributeType::Select->value,
            'sort_order' => 2,
        ])
        ->assertRedirect();

    expect(ProductAttribute::query()->where('slug', 'ukuran-sepatu')->exists())->toBeTrue();
});

it('refuses a duplicate attribute name', function () {
    ProductAttribute::factory()->create(['name' => 'Warna']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.product-attributes.store'), [
            'name' => 'Warna',
            'type' => ProductAttributeType::Select->value,
        ])
        ->assertSessionHasErrors('name');
});

it('refuses attribute writes from a branch admin', function () {
    $attribute = ProductAttribute::factory()->create(['name' => 'Warna']);

    $actor = User::factory()->adminCabang()->create();

    $this->actingAs($actor)
        ->post(route('admin.product-attributes.store'), ['name' => 'Bahan', 'type' => 'select'])
        ->assertForbidden();

    $this->actingAs($actor)
        ->put(route('admin.product-attributes.update', $attribute), ['name' => 'Corak'])
        ->assertForbidden();

    $this->actingAs($actor)
        ->delete(route('admin.product-attributes.destroy', $attribute))
        ->assertForbidden();

    expect($attribute->fresh()->name)->toBe('Warna');
});

it('deletes an unused attribute together with its values', function () {
    $attribute = ProductAttribute::factory()->withValues(['Merah'])->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.product-attributes.destroy', $attribute))
        ->assertRedirect();

    expect(ProductAttribute::query()->whereKey($attribute->id)->exists())->toBeFalse();
});

it('refuses to delete an attribute that still defines a variant', function () {
    $attribute = ProductAttribute::factory()->withValues(['Merah'])->create();
    $variant = ProductVariant::factory()->for(Product::factory()->variable())->create();
    $variant->attributeValues()->sync($attribute->values()->pluck('id')->all());

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.product-attributes.destroy', $attribute))
        ->assertForbidden();

    expect(ProductAttribute::query()->whereKey($attribute->id)->exists())->toBeTrue();
});

it('reports why an attribute in use cannot be deleted', function () {
    $attribute = ProductAttribute::factory()->withValues(['Merah'])->create();
    $variant = ProductVariant::factory()->for(Product::factory()->variable())->create();
    $variant->attributeValues()->sync($attribute->values()->pluck('id')->all());

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.product-attributes.index'))
        ->assertInertia(fn ($page) => $page
            ->where('attributes.0.can.delete', false)
            ->where('attributes.0.variant_usage_count', 1)
            ->whereNot('attributes.0.deletion_blocked_reason', null));
});

it('adds a value to an attribute', function () {
    $attribute = ProductAttribute::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.product-attributes.values.store', $attribute), [
            'value' => 'XXL',
            'label' => 'Extra Extra Large',
            'sort_order' => 5,
        ])
        ->assertRedirect();

    expect($attribute->values()->where('value', 'XXL')->value('label'))->toBe('Extra Extra Large');
});

it('refuses a duplicate value within one attribute', function () {
    $attribute = ProductAttribute::factory()->withValues(['XL'])->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.product-attributes.values.store', $attribute), ['value' => 'XL'])
        ->assertSessionHasErrors('value');
});

it('allows the same value under a different attribute', function () {
    ProductAttribute::factory()->withValues(['Merah'])->create();
    $other = ProductAttribute::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.product-attributes.values.store', $other), ['value' => 'Merah'])
        ->assertRedirect();

    expect($other->values()->where('value', 'Merah')->exists())->toBeTrue();
});

it('requires a colour code on a colour attribute', function () {
    $attribute = ProductAttribute::factory()->color()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.product-attributes.values.store', $attribute), ['value' => 'Merah'])
        ->assertSessionHasErrors('color_hex');
});

it('refuses a colour code on an attribute that is not a colour', function () {
    $attribute = ProductAttribute::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.product-attributes.values.store', $attribute), [
            'value' => 'XL',
            'color_hex' => '#c62828',
        ])
        ->assertSessionHasErrors('color_hex');
});

it('stores a colour code in one spelling', function () {
    $attribute = ProductAttribute::factory()->color()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.product-attributes.values.store', $attribute), [
            'value' => 'Merah',
            'color_hex' => '#C62828',
        ])
        ->assertRedirect();

    expect($attribute->values()->where('value', 'Merah')->value('color_hex'))->toBe('#c62828');
});

it('refuses to delete a value that still defines a variant', function () {
    $attribute = ProductAttribute::factory()->withValues(['Merah'])->create();
    $value = $attribute->values()->sole();
    $variant = ProductVariant::factory()->for(Product::factory()->variable())->create();
    $variant->attributeValues()->sync([$value->id]);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.product-attributes.values.destroy', [$attribute, $value]))
        ->assertForbidden();

    expect($attribute->values()->whereKey($value->id)->exists())->toBeTrue();
});

it('404s on a value belonging to another attribute', function () {
    $attribute = ProductAttribute::factory()->create();
    $foreign = ProductAttribute::factory()->withValues(['Merah'])->create()->values()->sole();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.product-attributes.values.destroy', [$attribute, $foreign]))
        ->assertNotFound();
});

it('keeps an attribute type fixed once values exist', function () {
    $attribute = ProductAttribute::factory()->create(['name' => 'Ukuran']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.product-attributes.update', $attribute), [
            'name' => 'Ukuran',
            'type' => ProductAttributeType::Color->value,
            'sort_order' => 0,
        ])
        ->assertRedirect();

    expect($attribute->fresh()->type)->toBe(ProductAttributeType::Select);
});
