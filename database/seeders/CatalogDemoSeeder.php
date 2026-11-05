<?php

namespace Database\Seeders;

use App\Actions\Category\CreateCategory;
use App\Actions\Product\CreateProduct;
use App\Actions\Product\GenerateProductVariants;
use App\Enums\ProductAttributeType;
use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A small catalogue for local development: a three-level category tree, a
 * handful of products filed under it, and a grid of variants on the two
 * products that have options.
 *
 * Built through the same Actions the panel uses, so what the seeder produces
 * can never drift from what the screens produce — every product gets its
 * default variant (ADR-020), every slug goes through the same uniqueness
 * check, and every variant grid through the same generator.
 *
 * Re-running it is safe: a category, product or attribute that is already
 * there is left alone rather than created a second time under a suffixed slug.
 * That is what makes it usable during development, where the seeder is run
 * again after new entries are added to the lists below. Never run it against a
 * production database.
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

    /**
     * Attribute name => [type, value => swatch], shared by the whole catalogue
     * (ADR-021).
     *
     * The swatch sits next to the value it belongs to, and is null on an
     * attribute that is not a colour — which is exactly what the form request
     * demands of a value.
     *
     * @var array<string, array{0: ProductAttributeType, 1: array<string, string|null>}>
     */
    private const ATTRIBUTES = [
        'Warna' => [ProductAttributeType::Color, [
            'Hitam' => '#1f2937',
            'Putih' => '#f9fafb',
            'Navy' => '#1e3a8a',
        ]],
        'Ukuran' => [ProductAttributeType::Select, [
            'S' => null,
            'M' => null,
            'L' => null,
            'XL' => null,
        ]],
    ];

    /**
     * Product slug => the attributes its variants are generated from.
     *
     * @var array<string, array<int, string>>
     */
    private const VARIANT_GRIDS = [
        'kaos-polos-katun-combed' => ['Warna', 'Ukuran'],
        'sepatu-lari-ringan' => ['Ukuran'],
    ];

    public function run(
        CreateCategory $createCategory,
        CreateProduct $createProduct,
        GenerateProductVariants $generateVariants,
    ): void {
        $this->seedTree($createCategory);

        foreach (self::PRODUCTS as $index => [$categorySlug, $name, $price, $weight, $type]) {
            if (Product::withTrashed()->where('slug', Str::slug($name))->exists()) {
                continue;
            }

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

        $this->seedAttributes();
        $this->seedVariantGrids($generateVariants);
    }

    /**
     * The catalogue-wide attributes, idempotently: re-running the seeder must
     * not add a second "Warna".
     */
    private function seedAttributes(): void
    {
        $position = 0;

        foreach (self::ATTRIBUTES as $name => [$type, $values]) {
            $attribute = ProductAttribute::query()->firstOrCreate(
                ['name' => $name],
                ['type' => $type, 'sort_order' => $position++],
            );

            $valuePosition = 0;

            foreach ($values as $value => $colorHex) {
                $attribute->values()->firstOrCreate(
                    ['value' => $value],
                    ['color_hex' => $colorHex, 'sort_order' => $valuePosition++],
                );
            }
        }
    }

    /**
     * Generates the variants of the products that have options, through the
     * same Action the variant editor uses (P1-E3-T6) — so the seeder cannot
     * produce a grid the panel would refuse.
     */
    private function seedVariantGrids(GenerateProductVariants $generateVariants): void
    {
        foreach (self::VARIANT_GRIDS as $productSlug => $attributeNames) {
            $product = Product::query()->where('slug', $productSlug)->first();

            if (! $product instanceof Product) {
                continue;
            }

            $groups = array_map(
                fn (string $name): array => ProductAttribute::query()
                    ->where('name', $name)
                    ->firstOrFail()
                    ->values()
                    ->ordered()
                    ->pluck('id')
                    ->all(),
                $attributeNames,
            );

            $generateVariants->handle($product, $groups);
        }
    }

    private function seedTree(CreateCategory $createCategory): void
    {
        $position = 0;

        foreach (self::TREE as $rootName => $children) {
            $root = $this->category($createCategory, $rootName, null, $position++);

            $childPosition = 0;

            foreach ($children as $childName => $leaves) {
                $child = $this->category($createCategory, $childName, $root->getKey(), $childPosition++);

                foreach ($leaves as $leafPosition => $leafName) {
                    $this->category($createCategory, $leafName, $child->getKey(), $leafPosition);
                }
            }
        }
    }

    /**
     * One category, created only if its slug is not taken — so re-running the
     * seeder does not produce "elektronik-2".
     */
    private function category(CreateCategory $createCategory, string $name, ?int $parentId, int $sortOrder): Category
    {
        $existing = Category::withTrashed()->where('slug', Str::slug($name))->first();

        if ($existing instanceof Category) {
            return $existing;
        }

        return $createCategory->handle([
            'parent_id' => $parentId,
            'name' => $name,
            'is_active' => true,
            'sort_order' => $sortOrder,
        ]);
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
