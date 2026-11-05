<?php

namespace App\Actions\Product;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Stores uploaded photos in a product's gallery (P1-E3-T7).
 *
 * Files go on the `public` disk under one folder per product, so a product's
 * photos can be found and cleared together. The stored name is generated, not
 * the uploaded one: a customer-facing URL should not carry
 * `WhatsApp Image 2026-11-05 at 14.32.jpg`, and two uploads of `foto.jpg`
 * must not collide.
 *
 * The first photo a product ever gets becomes its primary one, because a
 * gallery with photos and no primary would leave every listing without a
 * thumbnail until someone noticed. New photos go to the end of the order.
 */
final class StoreProductImages
{
    /** Where a product's photos live on the disk. */
    public const DIRECTORY = 'products';

    /** The disk a product's photos are written to. */
    public const DISK = 'public';

    /**
     * @param  array<int, UploadedFile>  $files
     * @param  int|null  $variantId  Set when the photos show one specific variant.
     * @return Collection<int, ProductImage>
     */
    public function handle(Product $product, array $files, ?int $variantId = null): Collection
    {
        return DB::transaction(function () use ($product, $files, $variantId): Collection {
            $nextSortOrder = (int) $product->images()->max('sort_order');
            $hasPrimary = $product->images()->where('is_primary', true)->exists();
            $stored = new Collection;

            foreach ($files as $file) {
                $path = $file->store(self::DIRECTORY.'/'.$product->getKey(), self::DISK);

                $image = $product->images()->create([
                    'product_variant_id' => $variantId,
                    'path' => (string) $path,
                    'sort_order' => ++$nextSortOrder,
                    'is_primary' => ! $hasPrimary,
                ]);

                $hasPrimary = true;
                $stored->push($image);
            }

            return $stored;
        });
    }
}
