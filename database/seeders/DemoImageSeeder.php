<?php

namespace Database\Seeders;

use App\Actions\Category\UpdateCategory;
use App\Actions\Product\StoreProductImages;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * Pictures for the demo catalogue, drawn by Faker (`DemoImages`).
 *
 * Every product gets a primary photo, and about half a second one, through
 * `StoreProductImages` — the action behind the product gallery upload — so
 * the files land where the panel puts them and the first one becomes the
 * primary. Every category gets a picture for the storefront's category tiles.
 *
 * Without a reachable image service (or under the test suite) nothing is
 * stored and the storefront falls back to its empty frames. Re-running only
 * fills products and categories that still have no picture. Never run it
 * against a production database.
 */
class DemoImageSeeder extends Seeder
{
    public function run(StoreProductImages $storeImages, UpdateCategory $updateCategory): void
    {
        $products = Product::query()->doesntHave('images')->orderBy('id')->get();

        foreach ($products as $product) {
            $files = array_values(array_filter([
                DemoImages::upload($product->name),
                fake()->boolean(50) ? DemoImages::upload($product->name.' 2') : null,
            ]));

            if ($files === []) {
                continue;
            }

            $storeImages->handle($product, $files);

            array_walk($files, fn (UploadedFile $file) => DemoImages::discard($file));
        }

        foreach (Category::query()->whereNull('image_path')->orderBy('id')->get() as $category) {
            $path = DemoImages::store('categories', $category->name, 600, 400);

            if ($path !== null) {
                $updateCategory->handle($category, ['image_path' => $path]);
            }
        }
    }
}
