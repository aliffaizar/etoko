<?php

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductVariant;
use App\Models\User;

/*
 * The variant editor (P1-E3-T6).
 *
 * These run against the real permission baseline from config/rbac.php, so a
 * change there that widens access shows up here. Writes demand a central actor
 * (BR-1), which is more than the permission alone.
 */

function variantPayload(array $overrides = []): array
{
    return [
        'sku' => 'KMJ-FLN-HIJAU-XL',
        'barcode' => '8991102000987',
        'name' => 'Hijau / XL',
        'price' => 210000,
        'compare_at_price' => null,
        'cost_price' => 150000,
        'weight_gram' => 450,
        'length_cm' => null,
        'width_cm' => null,
        'height_cm' => null,
        'is_active' => true,
        'attribute_value_ids' => [],
        ...$overrides,
    ];
}

function variableProduct(): Product
{
    return Product::factory()->variable()->withDefaultVariant()->create();
}

it('redirects guests to the login page', function () {
    $product = variableProduct();

    $this->get(route('admin.products.variants.index', $product))->assertRedirect(route('login'));
});

it('shows the editor to every role holding products.view', function (UserRole $role) {
    $product = variableProduct();

    $this->actingAs(User::factory()->withRole($role)->forBranch()->create())
        ->get(route('admin.products.variants.index', $product))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/products/variants')
            ->has('variants', 1)
            ->has('options.attributes'));
})->with([
    'super admin' => [UserRole::SuperAdmin],
    'admin pusat' => [UserRole::AdminPusat],
    'admin cabang' => [UserRole::AdminCabang],
    'staff gudang' => [UserRole::StaffGudang],
    'kasir' => [UserRole::Kasir],
]);

it('forbids customers', function () {
    $this->actingAs(User::factory()->customer()->create())
        ->get(route('admin.products.variants.index', variableProduct()))
        ->assertForbidden();
});

it('offers writing only to central staff', function () {
    $product = variableProduct();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.products.variants.index', $product))
        ->assertInertia(fn ($page) => $page->where('can.update', true)->where('can.generate', true));

    $this->actingAs(User::factory()->adminCabang()->create())
        ->get(route('admin.products.variants.index', $product))
        ->assertInertia(fn ($page) => $page->where('can.update', false)->where('can.generate', false));
});

it('reports the effective price a variant inherits from its product', function () {
    $product = Product::factory()->variable()->create(['base_price' => 180000, 'weight_gram' => 500]);
    ProductVariant::factory()->for($product)->default()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.products.variants.index', $product))
        ->assertInertia(fn ($page) => $page
            ->where('variants.0.price', null)
            ->where('variants.0.effective_price', 180000)
            ->where('variants.0.effective_weight_gram', 500));
});

it('generates the variants the chosen options imply', function () {
    $product = variableProduct();
    $colour = ProductAttribute::factory()->withValues(['Merah', 'Biru'])->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.variants.generate', $product), [
            'options' => [['attribute_id' => $colour->id, 'value_ids' => $colour->values()->pluck('id')->all()]],
        ])
        ->assertRedirect();

    expect($product->variants()->count())->toBe(2);
});

it('refuses to generate variants for a branch admin', function () {
    $product = variableProduct();
    $colour = ProductAttribute::factory()->withValues(['Merah'])->create();

    $this->actingAs(User::factory()->adminCabang()->create())
        ->post(route('admin.products.variants.generate', $product), [
            'options' => [['attribute_id' => $colour->id, 'value_ids' => $colour->values()->pluck('id')->all()]],
        ])
        ->assertForbidden();

    expect($product->variants()->count())->toBe(1);
});

it('refuses to generate variants for a simple product', function () {
    $product = Product::factory()->withDefaultVariant()->create();
    $colour = ProductAttribute::factory()->withValues(['Merah'])->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.variants.generate', $product), [
            'options' => [['attribute_id' => $colour->id, 'value_ids' => $colour->values()->pluck('id')->all()]],
        ])
        ->assertForbidden();

    expect($product->variants()->count())->toBe(1);
});

it('refuses a value that does not belong to the attribute it was sent under', function () {
    $product = variableProduct();
    $colour = ProductAttribute::factory()->withValues(['Merah'])->create();
    $size = ProductAttribute::factory()->withValues(['XL'])->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.variants.generate', $product), [
            'options' => [['attribute_id' => $colour->id, 'value_ids' => $size->values()->pluck('id')->all()]],
        ])
        ->assertSessionHasErrors('options.0.value_ids');

    expect($product->variants()->count())->toBe(1);
});

it('refuses the same attribute twice in one generation', function () {
    $product = variableProduct();
    $colour = ProductAttribute::factory()->withValues(['Merah', 'Biru'])->create();
    $valueIds = $colour->values()->pluck('id')->all();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.variants.generate', $product), [
            'options' => [
                ['attribute_id' => $colour->id, 'value_ids' => [$valueIds[0]]],
                ['attribute_id' => $colour->id, 'value_ids' => [$valueIds[1]]],
            ],
        ])
        ->assertSessionHasErrors('options.1.attribute_id');
});

it('refuses a grid larger than the variant ceiling', function () {
    $product = variableProduct();
    $first = ProductAttribute::factory()->withValues(range('A', 'O'))->create();
    $second = ProductAttribute::factory()->withValues(range('a', 'o'))->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.variants.generate', $product), [
            'options' => [
                ['attribute_id' => $first->id, 'value_ids' => $first->values()->pluck('id')->all()],
                ['attribute_id' => $second->id, 'value_ids' => $second->values()->pluck('id')->all()],
            ],
        ])
        ->assertSessionHasErrors('options');

    expect($product->variants()->count())->toBe(1);
});

it('adds a variant by hand', function () {
    $product = variableProduct();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.variants.store', $product), variantPayload())
        ->assertRedirect();

    expect($product->variants()->where('sku', 'KMJ-FLN-HIJAU-XL')->exists())->toBeTrue();
});

it('upper-cases a submitted sku', function () {
    $product = variableProduct();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.variants.store', $product), variantPayload(['sku' => 'kmj-fln-hijau-xl']));

    expect($product->variants()->where('sku', 'KMJ-FLN-HIJAU-XL')->exists())->toBeTrue();
});

it('refuses a sku another variant already holds', function () {
    $product = variableProduct();
    ProductVariant::factory()->for(Product::factory())->create(['sku' => 'TAKEN-01']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.variants.store', $product), variantPayload(['sku' => 'TAKEN-01']))
        ->assertSessionHasErrors('sku');
});

it('refuses two values of the same attribute on one variant', function () {
    $product = variableProduct();
    $colour = ProductAttribute::factory()->withValues(['Merah', 'Biru'])->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.variants.store', $product), variantPayload([
            'attribute_value_ids' => $colour->values()->pluck('id')->all(),
        ]))
        ->assertSessionHasErrors('attribute_value_ids');
});

it('refuses a combination the product already has', function () {
    $product = variableProduct();
    $colour = ProductAttribute::factory()->withValues(['Merah'])->create();
    $valueId = $colour->values()->value('id');

    $actor = User::factory()->adminPusat()->create();

    $this->actingAs($actor)
        ->post(route('admin.products.variants.store', $product), variantPayload([
            'attribute_value_ids' => [$valueId],
        ]))
        ->assertRedirect();

    $this->actingAs($actor)
        ->post(route('admin.products.variants.store', $product), variantPayload([
            'sku' => 'KMJ-FLN-LAIN',
            'barcode' => null,
            'attribute_value_ids' => [$valueId],
        ]))
        ->assertSessionHasErrors('attribute_value_ids');
});

it('updates a variant and stores an emptied override as null', function () {
    $product = Product::factory()->variable()->create(['base_price' => 180000]);
    $default = ProductVariant::factory()->for($product)->default()->create(['sku' => 'KMJ-FLN']);
    $variant = ProductVariant::factory()->for($product)->create(['price' => 210000]);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.products.variants.update', [$product, $variant]), variantPayload([
            'sku' => $variant->sku,
            'barcode' => null,
            'price' => null,
            'cost_price' => null,
            'weight_gram' => null,
        ]))
        ->assertRedirect();

    expect($variant->fresh()->price)->toBeNull()
        ->and($variant->fresh()->effectivePrice())->toBe(180000)
        ->and($default->fresh()->is_default)->toBeTrue();
});

it('refuses deactivating the default variant', function () {
    $product = variableProduct();
    $default = $product->variants()->sole();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.products.variants.update', [$product, $default]), variantPayload([
            'sku' => $default->sku,
            'barcode' => null,
            'is_active' => false,
        ]))
        ->assertSessionHasErrors('is_active');

    expect($default->fresh()->is_active)->toBeTrue();
});

it('hands the default flag over, demoting the previous holder', function () {
    $product = Product::factory()->variable()->create();
    $first = ProductVariant::factory()->for($product)->default()->create();
    $second = ProductVariant::factory()->for($product)->inactive()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->patch(route('admin.products.variants.default.update', [$product, $second]))
        ->assertRedirect();

    expect($second->fresh()->is_default)->toBeTrue()
        ->and($second->fresh()->is_active)->toBeTrue()
        ->and($first->fresh()->is_default)->toBeFalse();
});

it('deletes a variant that is neither the default nor the last', function () {
    $product = Product::factory()->variable()->create();
    ProductVariant::factory()->for($product)->default()->create();
    $variant = ProductVariant::factory()->for($product)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.products.variants.destroy', [$product, $variant]))
        ->assertRedirect();

    expect($variant->fresh()->trashed())->toBeTrue()
        ->and($product->variants()->count())->toBe(1);
});

it('refuses to delete the default variant', function () {
    $product = Product::factory()->variable()->create();
    $default = ProductVariant::factory()->for($product)->default()->create();
    ProductVariant::factory()->for($product)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.products.variants.destroy', [$product, $default]))
        ->assertForbidden();

    expect($default->fresh()->trashed())->toBeFalse();
});

it('refuses to delete a product last variant', function () {
    $product = variableProduct();
    $only = $product->variants()->sole();
    $only->update(['is_default' => false]);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.products.variants.destroy', [$product, $only]))
        ->assertForbidden();

    expect($only->fresh()->trashed())->toBeFalse();
});

it('refuses every write to a branch admin', function (string $method, string $route) {
    $product = variableProduct();
    $variant = $product->variants()->sole();

    $this->actingAs(User::factory()->adminCabang()->create())
        ->{$method}(route($route, [$product, $variant]), variantPayload(['sku' => $variant->sku, 'barcode' => null]))
        ->assertForbidden();
})->with([
    'update' => ['put', 'admin.products.variants.update'],
    'make default' => ['patch', 'admin.products.variants.default.update'],
    'delete' => ['delete', 'admin.products.variants.destroy'],
]);

it('404s on a variant of another product', function () {
    $product = variableProduct();
    $foreign = ProductVariant::factory()->for(Product::factory()->variable())->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.products.variants.destroy', [$product, $foreign]))
        ->assertNotFound();

    expect($foreign->fresh()->trashed())->toBeFalse();
});
