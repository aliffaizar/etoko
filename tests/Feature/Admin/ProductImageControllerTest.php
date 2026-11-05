<?php

use App\Actions\Product\StoreProductImages;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * The product photo gallery (P1-E3-T7).
 *
 * The disk is faked throughout, so nothing is written to storage/app/public
 * and the assertions can speak about files directly.
 */

beforeEach(function () {
    Storage::fake(StoreProductImages::DISK);
});

function photo(string $name = 'foto.jpg', int $width = 800, int $height = 800): UploadedFile
{
    return UploadedFile::fake()->image($name, $width, $height);
}

it('redirects guests to the login page', function () {
    $product = Product::factory()->withDefaultVariant()->create();

    $this->get(route('admin.products.images.index', $product))->assertRedirect(route('login'));
});

it('shows the gallery to every role holding products.view', function (UserRole $role) {
    $product = Product::factory()->withDefaultVariant()->create();
    ProductImage::factory()->for($product)->primary()->create();

    $this->actingAs(User::factory()->withRole($role)->forBranch()->create())
        ->get(route('admin.products.images.index', $product))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/products/images')
            ->has('images', 1)
            ->has('options.variants', 1));
})->with([
    'super admin' => [UserRole::SuperAdmin],
    'admin pusat' => [UserRole::AdminPusat],
    'admin cabang' => [UserRole::AdminCabang],
    'staff gudang' => [UserRole::StaffGudang],
    'kasir' => [UserRole::Kasir],
]);

it('forbids customers', function () {
    $this->actingAs(User::factory()->customer()->create())
        ->get(route('admin.products.images.index', Product::factory()->create()))
        ->assertForbidden();
});

it('offers writing only to central staff', function () {
    $product = Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.products.images.index', $product))
        ->assertInertia(fn ($page) => $page->where('can.update', true));

    $this->actingAs(User::factory()->adminCabang()->create())
        ->get(route('admin.products.images.index', $product))
        ->assertInertia(fn ($page) => $page->where('can.update', false));
});

it('uploads several photos at once and stores the files', function () {
    $product = Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.images.store', $product), [
            'images' => [photo('satu.jpg'), photo('dua.png')],
        ])
        ->assertRedirect();

    expect($product->images()->count())->toBe(2);

    foreach ($product->images as $image) {
        Storage::disk(StoreProductImages::DISK)->assertExists($image->path);
        expect($image->path)->toStartWith('products/'.$product->id.'/');
    }
});

it('makes the first photo of a product its primary one', function () {
    $product = Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.images.store', $product), ['images' => [photo(), photo('dua.jpg')]]);

    expect($product->images()->where('is_primary', true)->count())->toBe(1)
        ->and($product->images()->orderBy('sort_order')->first()->is_primary)->toBeTrue();
});

it('does not hand the primary flag to a later upload', function () {
    $product = Product::factory()->withDefaultVariant()->create();
    $first = ProductImage::factory()->for($product)->primary()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.images.store', $product), ['images' => [photo()]]);

    expect($first->fresh()->is_primary)->toBeTrue()
        ->and($product->images()->where('is_primary', true)->count())->toBe(1);
});

it('appends new photos to the end of the order', function () {
    $product = Product::factory()->withDefaultVariant()->create();
    ProductImage::factory()->for($product)->primary()->sortedAt(7)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.images.store', $product), ['images' => [photo()]]);

    expect($product->images()->where('is_primary', false)->value('sort_order'))->toBe(8);
});

it('ties an upload to a variant when one is named', function () {
    $product = Product::factory()->variable()->withDefaultVariant()->create();
    $variant = $product->variants()->sole();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.images.store', $product), [
            'images' => [photo()],
            'product_variant_id' => $variant->id,
        ])
        ->assertRedirect();

    expect($product->images()->value('product_variant_id'))->toBe($variant->id);
});

it('refuses a variant belonging to another product', function () {
    $product = Product::factory()->withDefaultVariant()->create();
    $foreign = ProductVariant::factory()->for(Product::factory())->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.images.store', $product), [
            'images' => [photo()],
            'product_variant_id' => $foreign->id,
        ])
        ->assertSessionHasErrors('product_variant_id');

    expect($product->images()->count())->toBe(0);
});

it('refuses a file that is not an image', function () {
    $product = Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.images.store', $product), [
            'images' => [UploadedFile::fake()->create('katalog.pdf', 100, 'application/pdf')],
        ])
        ->assertSessionHasErrors('images.0');

    expect($product->images()->count())->toBe(0);
});

it('refuses a photo too small to use as a thumbnail', function () {
    $product = Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.images.store', $product), ['images' => [photo('kecil.jpg', 80, 80)]])
        ->assertSessionHasErrors('images.0');
});

it('refuses more photos than one upload may carry', function () {
    $product = Product::factory()->withDefaultVariant()->create();

    $files = array_map(fn (int $index): UploadedFile => photo("foto-{$index}.jpg"), range(1, 11));

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.images.store', $product), ['images' => $files])
        ->assertSessionHasErrors('images');

    expect($product->images()->count())->toBe(0);
});

it('updates a photo alt text and the variant it shows', function () {
    $product = Product::factory()->variable()->withDefaultVariant()->create();
    $variant = $product->variants()->sole();
    $image = ProductImage::factory()->for($product)->primary()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.products.images.update', [$product, $image]), [
            'alt_text' => 'Kaos hijau dilihat dari depan',
            'product_variant_id' => $variant->id,
        ])
        ->assertRedirect();

    expect($image->fresh()->alt_text)->toBe('Kaos hijau dilihat dari depan')
        ->and($image->fresh()->product_variant_id)->toBe($variant->id);
});

it('unties a photo from its variant when the variant is cleared', function () {
    $product = Product::factory()->variable()->withDefaultVariant()->create();
    $variant = $product->variants()->sole();
    $image = ProductImage::factory()->forVariant($variant)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.products.images.update', [$product, $image]), [
            'alt_text' => '',
            'product_variant_id' => '',
        ])
        ->assertRedirect();

    expect($image->fresh()->product_variant_id)->toBeNull()
        ->and($image->fresh()->alt_text)->toBeNull();
});

it('hands the primary flag over, demoting the previous holder', function () {
    $product = Product::factory()->withDefaultVariant()->create();
    $first = ProductImage::factory()->for($product)->primary()->create();
    $second = ProductImage::factory()->for($product)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->patch(route('admin.products.images.primary.update', [$product, $second]))
        ->assertRedirect();

    expect($second->fresh()->is_primary)->toBeTrue()
        ->and($first->fresh()->is_primary)->toBeFalse();
});

it('writes the submitted order', function () {
    $product = Product::factory()->withDefaultVariant()->create();
    $images = ProductImage::factory()->for($product)->count(3)->create();

    $reversed = $images->pluck('id')->reverse()->values()->all();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.products.images.reorder', $product), ['image_ids' => $reversed])
        ->assertRedirect();

    expect($product->images()->orderBy('sort_order')->pluck('id')->all())->toBe($reversed);
});

it('puts photos the reorder did not mention after the ones it did', function () {
    $product = Product::factory()->withDefaultVariant()->create();
    $images = ProductImage::factory()->for($product)->count(3)->create();
    $named = [$images[2]->id, $images[0]->id];

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.products.images.reorder', $product), ['image_ids' => $named]);

    expect($product->images()->orderBy('sort_order')->pluck('id')->all())
        ->toBe([...$named, $images[1]->id]);
});

it('refuses a reorder naming a photo of another product', function () {
    $product = Product::factory()->withDefaultVariant()->create();
    $own = ProductImage::factory()->for($product)->create();
    $foreign = ProductImage::factory()->for(Product::factory())->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.products.images.reorder', $product), [
            'image_ids' => [$own->id, $foreign->id],
        ])
        ->assertSessionHasErrors('image_ids.1');
});

it('deletes a photo together with its file', function () {
    $product = Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.images.store', $product), ['images' => [photo(), photo('dua.jpg')]]);

    $image = $product->images()->where('is_primary', false)->sole();
    $path = $image->path;

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.products.images.destroy', [$product, $image]))
        ->assertRedirect();

    expect(ProductImage::query()->whereKey($image->id)->exists())->toBeFalse();
    Storage::disk(StoreProductImages::DISK)->assertMissing($path);
});

it('hands the primary flag to the next photo when the primary is deleted', function () {
    $product = Product::factory()->withDefaultVariant()->create();
    $primary = ProductImage::factory()->for($product)->primary()->sortedAt(0)->create();
    $next = ProductImage::factory()->for($product)->sortedAt(1)->create();
    ProductImage::factory()->for($product)->sortedAt(2)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.products.images.destroy', [$product, $primary]));

    expect($next->fresh()->is_primary)->toBeTrue()
        ->and($product->images()->where('is_primary', true)->count())->toBe(1);
});

it('leaves an emptied gallery without a primary photo', function () {
    $product = Product::factory()->withDefaultVariant()->create();
    $only = ProductImage::factory()->for($product)->primary()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.products.images.destroy', [$product, $only]));

    expect($product->images()->count())->toBe(0);
});

it('keeps the file when another row still points at it', function () {
    $product = Product::factory()->withDefaultVariant()->create();
    Storage::disk(StoreProductImages::DISK)->put('products/shared.webp', 'x');

    $first = ProductImage::factory()->for($product)->primary()->create(['path' => 'products/shared.webp']);
    ProductImage::factory()->for($product)->create(['path' => 'products/shared.webp']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.products.images.destroy', [$product, $first]));

    Storage::disk(StoreProductImages::DISK)->assertExists('products/shared.webp');
});

it('refuses every write to a branch admin', function (string $method, string $route) {
    $product = Product::factory()->withDefaultVariant()->create();
    $image = ProductImage::factory()->for($product)->primary()->create();

    $this->actingAs(User::factory()->adminCabang()->create())
        ->{$method}(route($route, [$product, $image]), ['alt_text' => 'Diubah'])
        ->assertForbidden();

    expect($image->fresh()->alt_text)->toBeNull();
})->with([
    'update' => ['put', 'admin.products.images.update'],
    'make primary' => ['patch', 'admin.products.images.primary.update'],
    'delete' => ['delete', 'admin.products.images.destroy'],
]);

it('refuses an upload from a branch admin', function () {
    $product = Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminCabang()->create())
        ->post(route('admin.products.images.store', $product), ['images' => [photo()]])
        ->assertForbidden();

    expect($product->images()->count())->toBe(0);
});

it('404s on a photo of another product', function () {
    $product = Product::factory()->withDefaultVariant()->create();
    $foreign = ProductImage::factory()->for(Product::factory())->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.products.images.destroy', [$product, $foreign]))
        ->assertNotFound();

    expect(ProductImage::query()->whereKey($foreign->id)->exists())->toBeTrue();
});

it('keeps a photo in the gallery when the variant it showed is deleted', function () {
    $product = Product::factory()->variable()->create();
    ProductVariant::factory()->for($product)->default()->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $image = ProductImage::factory()->forVariant($variant)->create();

    // Force deleted, because `nullOnDelete` is about the row actually going.
    $variant->forceDelete();

    expect($image->fresh()->product_variant_id)->toBeNull()
        ->and($product->images()->count())->toBe(1);
});
