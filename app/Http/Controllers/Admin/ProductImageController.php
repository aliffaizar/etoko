<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Product\DeleteProductImage;
use App\Actions\Product\ReorderProductImages;
use App\Actions\Product\SetPrimaryProductImage;
use App\Actions\Product\StoreProductImages;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReorderProductImagesRequest;
use App\Http\Requests\Admin\StoreProductImageRequest;
use App\Http\Requests\Admin\UpdateProductImageRequest;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The photo gallery of one product (P1-E3-T7).
 *
 * Photos have no screen of their own: they are managed on the product they
 * belong to, so the routes are nested and authorisation asks `ProductPolicy`
 * about the product. Scoped route bindings make a photo of another product a
 * 404 rather than a silent cross-product edit.
 *
 * Two things the gallery guarantees, both so no listing is ever left without a
 * thumbnail: a product with photos always has exactly one primary photo, and
 * deleting the primary hands the flag to the next in order.
 *
 * A photo may name a variant, which is how the storefront swaps the gallery as
 * a buyer picks options. The variant going away leaves the photo in the
 * product's gallery rather than deleting it (`nullOnDelete`).
 */
class ProductImageController extends Controller
{
    public function index(Product $product): Response
    {
        $this->authorize('view', $product);

        /** @var User $actor */
        $actor = request()->user();
        $canUpdate = $actor->can('update', $product);

        return Inertia::render('admin/products/images', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'type_label' => $product->type->label(),
            ],
            'images' => $this->imageList($product, $canUpdate),
            'options' => ['variants' => $this->variantOptions($product)],
            'can' => ['update' => $canUpdate],
            'limits' => [
                'max_files' => StoreProductImageRequest::MAX_FILES,
                'max_kilobytes' => StoreProductImageRequest::MAX_KILOBYTES,
                'min_dimension' => StoreProductImageRequest::MIN_DIMENSION,
            ],
        ]);
    }

    public function store(
        StoreProductImageRequest $request,
        Product $product,
        StoreProductImages $storeImages,
    ): RedirectResponse {
        $this->authorize('update', $product);

        $stored = $storeImages->handle($product, $request->uploadedImages(), $request->variantId());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "{$stored->count()} foto berhasil diunggah.",
        ]);

        return back();
    }

    public function update(
        UpdateProductImageRequest $request,
        Product $product,
        ProductImage $image,
    ): RedirectResponse {
        $this->authorize('update', $product);

        $image->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Perubahan foto berhasil disimpan.']);

        return back();
    }

    public function setPrimary(
        Product $product,
        ProductImage $image,
        SetPrimaryProductImage $setPrimary,
    ): RedirectResponse {
        $this->authorize('update', $product);

        $setPrimary->handle($image);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Foto utama produk diperbarui.']);

        return back();
    }

    public function reorder(
        ReorderProductImagesRequest $request,
        Product $product,
        ReorderProductImages $reorderImages,
    ): RedirectResponse {
        $this->authorize('update', $product);

        $reorderImages->handle($product, $request->imageIds());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Urutan foto disimpan.']);

        return back();
    }

    /**
     * Removes a photo and its file.
     *
     * Nothing blocks it: a photo is presentation, and an order line snapshots
     * what it needs (ADR-007). The primary flag moves on rather than being
     * lost.
     */
    public function destroy(
        Product $product,
        ProductImage $image,
        DeleteProductImage $deleteImage,
    ): RedirectResponse {
        $this->authorize('update', $product);

        $deleteImage->handle($image);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Foto dihapus.']);

        return back();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function imageList(Product $product, bool $canUpdate): array
    {
        return $product->images()
            ->with('variant:id,sku,name')
            ->ordered()
            ->get()
            ->map(fn (ProductImage $image): array => [
                'id' => $image->id,
                'url' => $image->url(),
                'path' => $image->path,
                'alt_text' => $image->alt_text,
                'sort_order' => $image->sort_order,
                'is_primary' => $image->is_primary,
                'product_variant_id' => $image->product_variant_id,
                // Not `displayName()`: that falls back to the product's name,
                // which would lazy-load the product on a relation selected
                // down to three columns, and `preventLazyLoading()` is on.
                'variant_label' => $image->variant === null
                    ? null
                    : ($image->variant->name ?? $image->variant->sku),
                'can' => [
                    'update' => $canUpdate,
                    'delete' => $canUpdate,
                    'make_primary' => $canUpdate && ! $image->is_primary,
                ],
            ])
            ->all();
    }

    /**
     * The variants a photo may be tied to.
     *
     * @return array<int, array{value: int, label: string}>
     */
    private function variantOptions(Product $product): array
    {
        return $product->variants()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (ProductVariant $variant): array => [
                'value' => $variant->id,
                'label' => $variant->name === null
                    ? $variant->sku
                    : "{$variant->name} · {$variant->sku}",
            ])
            ->all();
    }
}
