<?php

namespace App\Actions\Product;

use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Removes a photo from a product's gallery, file and all (P1-E3-T7).
 *
 * `product_images` has no `deleted_at`: a photo is a file, and a soft-deleted
 * row would keep a file nobody can see on the disk forever. The row and the
 * file go together.
 *
 * Deleting the primary photo hands the flag to the next one in order, so a
 * product with photos always has a thumbnail. Only an empty gallery has no
 * primary.
 */
final class DeleteProductImage
{
    public function handle(ProductImage $image): void
    {
        $path = $image->path;
        $productId = $image->product_id;
        $wasPrimary = $image->is_primary;

        DB::transaction(function () use ($image, $productId, $wasPrimary): void {
            $image->delete();

            if (! $wasPrimary) {
                return;
            }

            ProductImage::query()
                ->where('product_id', $productId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first()
                ?->fill(['is_primary' => true])
                ->save();
        });

        $this->deleteFileIfUnreferenced($path);
    }

    /**
     * The file is only removed once the last row pointing at it is gone.
     *
     * Two rows can share a path — a seeder or a factory may reuse one — and
     * deleting the file out from under the survivor would leave a broken
     * image in the gallery.
     */
    private function deleteFileIfUnreferenced(string $path): void
    {
        $stillUsed = ProductImage::query()->where('path', $path)->exists();

        if ($stillUsed) {
            return;
        }

        Storage::disk(StoreProductImages::DISK)->delete($path);
    }
}
