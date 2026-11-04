<?php

namespace Database\Seeders;

use App\Actions\Category\CreateCategory;
use App\Actions\Product\CreateProduct;
use App\Enums\ProductType;
use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * A small catalogue for local development: a three-level category tree and a
 * handful of products filed under it.
 *
 * Built through the same Actions the panel uses, so what the seeder produces
 * can never drift from what the screens produce — every product gets its
 * default variant (ADR-020) and every slug goes through the same uniqueness
 * check. Never run this against a production database.
 */
class CatalogDemoSeeder extends Seeder
{
    /**
     * Category name => subcategory name => leaf names.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const TREE = [
        'Elektronik' => [
            'Audio' => ['Headphone', 'Speaker Bluetooth'],
            'Komputer' => ['Keyboard', 'Mouse'],
        ],
        'Rumah Tangga' => [
            'Peralatan Dapur' => ['Blender', 'Rice Cooker'],
            'Pendingin Ruangan' => [],
        ],
        'Fesyen' => [
            'Pakaian Pria' => [],
            'Sepatu' => [],
        ],
    ];

    /**
     * Products as [category slug, name, price, weight in grams, type].
     *
     * @var array<int, array{0: string, 1: string, 2: int, 3: int, 4: ProductType}>
     */
    private const PRODUCTS = [
        ['headphone', 'Headphone Nirkabel ANC', 899_000, 280, ProductType::Simple],
        ['speaker-bluetooth', 'Speaker Bluetooth Tahan Air', 450_000, 620, ProductType::Simple],
        ['keyboard', 'Keyboard Mekanik TKL', 750_000, 850, ProductType::Simple],
        ['mouse', 'Mouse Nirkabel Senyap', 185_000, 95, ProductType::Simple],
        ['blender', 'Blender Kaca 1,5 Liter', 395_000, 3_200, ProductType::Simple],
        ['rice-cooker', 'Rice Cooker Digital 1,8 Liter', 625_000, 4_100, ProductType::Simple],
        ['pendingin-ruangan', 'Kipas Angin Berdiri 16 Inci', 310_000, 3_500, ProductType::Simple],
        ['pakaian-pria', 'Kaos Polos Katun Combed', 89_000, 220, ProductType::Variable],
        ['sepatu', 'Sepatu Lari Ringan', 540_000, 760, ProductType::Variable],
    ];

    public function run(CreateCategory $createCategory, CreateProduct $createProduct): void
    {
        $this->seedTree($createCategory);

        foreach (self::PRODUCTS as $index => [$categorySlug, $name, $price, $weight, $type]) {
            $createProduct->handle([
                'category_id' => Category::query()->where('slug', $categorySlug)->value('id'),
                'name' => $name,
                'type' => $type,
                'short_description' => "Contoh data {$name} untuk pengembangan lokal.",
                'base_price' => $price,
                'cost_price' => (int) ($price * 0.7),
                'weight_gram' => $weight,
                'is_active' => true,
                // Every third product is featured, so the storefront has
                // something to put on its front page.
                'is_featured' => $index % 3 === 0,
                'published_at' => now(),
                'sku' => $this->skuFor($name, $index + 1),
            ]);
        }
    }

    private function seedTree(CreateCategory $createCategory): void
    {
        $position = 0;

        foreach (self::TREE as $rootName => $children) {
            $root = $createCategory->handle([
                'name' => $rootName,
                'is_active' => true,
                'sort_order' => $position++,
            ]);

            $childPosition = 0;

            foreach ($children as $childName => $leaves) {
                $child = $createCategory->handle([
                    'parent_id' => $root->getKey(),
                    'name' => $childName,
                    'is_active' => true,
                    'sort_order' => $childPosition++,
                ]);

                foreach ($leaves as $leafPosition => $leafName) {
                    $createCategory->handle([
                        'parent_id' => $child->getKey(),
                        'name' => $leafName,
                        'is_active' => true,
                        'sort_order' => $leafPosition,
                    ]);
                }
            }
        }
    }

    /**
     * A readable, collision-free SKU from the product's initials and its
     * position in the list, e.g. "HNA-001".
     */
    private function skuFor(string $name, int $position): string
    {
        $initials = collect(explode(' ', $name))
            ->take(3)
            ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
            ->implode('');

        return $initials.'-'.str_pad((string) $position, 3, '0', STR_PAD_LEFT);
    }
}
