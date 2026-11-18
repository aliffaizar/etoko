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

/**
 * A fuller catalogue for demonstrating the shop, on top of the small one
 * `CatalogDemoSeeder` builds for the test suite.
 *
 * Adds four more departments, more subcategories under the existing three,
 * forty products across all of them, and the extra colours and a capacity
 * option the new variant grids need. The small seeder is left as it is
 * because its tests lock its exact counts; this one only adds.
 *
 * Built through the same Actions the panel uses, like the small one, so every
 * product gets its default variant, its slug and its SKU the way the screens
 * would give them. Re-running is safe: a category, product, attribute or
 * value already there is left alone. Never run it against a production
 * database.
 */
class ExtendedCatalogDemoSeeder extends Seeder
{
    /**
     * Category name => subcategory name => leaf names. A name that already
     * exists at that place is reused, so the existing tree is extended rather
     * than duplicated.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const TREE = [
        'Elektronik' => [
            'Audio' => ['Earbuds'],
            'Komputer' => [],
            'Gadget' => ['Powerbank', 'Jam Tangan Pintar'],
        ],
        'Rumah Tangga' => [
            'Peralatan Dapur' => ['Panci & Wajan'],
            'Dekorasi Rumah' => [],
        ],
        'Fesyen' => [
            'Pakaian Wanita' => [],
            'Tas' => [],
        ],
        'Kesehatan & Kecantikan' => [
            'Perawatan Kulit' => [],
            'Perawatan Rambut' => [],
        ],
        'Olahraga' => [
            'Fitness' => [],
            'Bersepeda' => [],
        ],
        'Makanan & Minuman' => [
            'Kopi & Teh' => [],
            'Camilan' => [],
        ],
        'Buku & Alat Tulis' => [
            'Alat Tulis' => [],
            'Buku' => [],
        ],
    ];

    /**
     * Products as [category name, name, price, weight in grams, featured].
     *
     * @var array<int, array{0: string, 1: string, 2: int, 3: int, 4: bool}>
     */
    private const PRODUCTS = [
        ['Earbuds', 'Earbuds TWS Bass Boost', 329_000, 60, true],
        ['Headphone', 'Headphone Gaming RGB', 499_000, 350, false],
        ['Speaker Bluetooth', 'Speaker Mini Portabel', 199_000, 300, false],
        ['Keyboard', 'Keyboard Nirkabel Slim', 279_000, 450, false],
        ['Mouse', 'Mouse Gaming 7 Tombol', 245_000, 120, false],
        ['Komputer', 'Webcam Full HD 1080p', 389_000, 180, false],
        ['Powerbank', 'Powerbank Fast Charging', 249_000, 380, true],
        ['Jam Tangan Pintar', 'Smartwatch Layar AMOLED', 1_299_000, 90, true],
        ['Blender', 'Chopper Listrik Mini', 159_000, 900, false],
        ['Rice Cooker', 'Rice Cooker Mini 0,8 Liter', 299_000, 2_200, false],
        ['Panci & Wajan', 'Wajan Anti Lengket 28 cm', 189_000, 1_200, false],
        ['Panci & Wajan', 'Set Panci Stainless 5 Pcs', 459_000, 4_500, true],
        ['Pendingin Ruangan', 'Air Cooler 8 Liter', 849_000, 7_000, false],
        ['Dekorasi Rumah', 'Lampu Tidur LED Aromaterapi', 129_000, 400, false],
        ['Dekorasi Rumah', 'Jam Dinding Minimalis 30 cm', 99_000, 600, false],
        ['Pakaian Pria', 'Kemeja Flanel Kotak', 179_000, 300, true],
        ['Pakaian Pria', 'Celana Chino Slim Fit', 219_000, 450, false],
        ['Pakaian Wanita', 'Blouse Rayon Lengan Panjang', 149_000, 200, false],
        ['Pakaian Wanita', 'Gamis Katun Polos', 259_000, 400, false],
        ['Sepatu', 'Sneakers Kanvas Klasik', 299_000, 800, true],
        ['Tas', 'Tas Ransel Laptop 15 Inci', 349_000, 900, false],
        ['Tas', 'Tas Selempang Kulit Sintetis', 189_000, 400, false],
        ['Perawatan Kulit', 'Serum Wajah Vitamin C 30 ml', 89_000, 80, true],
        ['Perawatan Kulit', 'Sunscreen SPF 50 PA++++', 69_000, 70, false],
        ['Perawatan Kulit', 'Pembersih Wajah Gentle 100 ml', 49_000, 120, false],
        ['Perawatan Rambut', 'Sampo Anti Ketombe 340 ml', 45_000, 380, false],
        ['Perawatan Rambut', 'Hair Dryer Lipat 1200 W', 179_000, 600, false],
        ['Fitness', 'Matras Yoga 6 mm', 159_000, 1_100, true],
        ['Fitness', 'Dumbbell Neoprene 2 x 3 kg', 199_000, 6_200, false],
        ['Fitness', 'Tali Skipping Digital', 59_000, 200, false],
        ['Bersepeda', 'Helm Sepeda Dewasa', 239_000, 350, false],
        ['Bersepeda', 'Lampu Sepeda USB Rechargeable', 79_000, 120, false],
        ['Kopi & Teh', 'Kopi Arabika Gayo 250 g', 95_000, 270, true],
        ['Kopi & Teh', 'Teh Hijau Celup Isi 25', 22_000, 80, false],
        ['Camilan', 'Keripik Singkong Pedas 200 g', 18_000, 210, false],
        ['Camilan', 'Granola Madu Almond 400 g', 65_000, 420, false],
        ['Alat Tulis', 'Pulpen Gel 0,5 mm Isi 12', 36_000, 150, false],
        ['Alat Tulis', 'Buku Catatan A5 Dotted', 42_000, 300, false],
        ['Buku', 'Buku Resep Masakan Nusantara', 88_000, 450, false],
        ['Buku', 'Belajar Pemrograman untuk Pemula', 120_000, 500, false],
    ];

    /**
     * Values added to the shared attributes, or attributes added whole
     * (ADR-021): attribute name => [type, value => swatch].
     *
     * @var array<string, array{0: ProductAttributeType, 1: array<string, string|null>}>
     */
    private const ATTRIBUTES = [
        'Warna' => [ProductAttributeType::Color, [
            'Merah' => '#dc2626',
            'Abu-abu' => '#6b7280',
        ]],
        'Ukuran' => [ProductAttributeType::Select, [
            'S' => null,
            'M' => null,
            'L' => null,
            'XL' => null,
        ]],
        'Kapasitas' => [ProductAttributeType::Select, [
            '10.000 mAh' => null,
            '20.000 mAh' => null,
        ]],
    ];

    /**
     * Product name => attribute name => the values its variants use, in the
     * order they should read.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const VARIANT_GRIDS = [
        'Powerbank Fast Charging' => ['Kapasitas' => ['10.000 mAh', '20.000 mAh']],
        'Smartwatch Layar AMOLED' => ['Warna' => ['Hitam', 'Abu-abu']],
        'Kemeja Flanel Kotak' => ['Warna' => ['Merah', 'Navy', 'Hitam'], 'Ukuran' => ['M', 'L', 'XL']],
        'Celana Chino Slim Fit' => ['Ukuran' => ['M', 'L', 'XL']],
        'Blouse Rayon Lengan Panjang' => ['Warna' => ['Putih', 'Navy'], 'Ukuran' => ['S', 'M', 'L']],
        'Gamis Katun Polos' => ['Ukuran' => ['S', 'M', 'L', 'XL']],
        'Sneakers Kanvas Klasik' => ['Warna' => ['Hitam', 'Putih']],
        'Tas Ransel Laptop 15 Inci' => ['Warna' => ['Hitam', 'Abu-abu', 'Navy']],
    ];

    /** Where this seeder's SKU numbering starts, clear of the small catalogue's. */
    private const SKU_OFFSET = 100;

    public function run(
        CreateCategory $createCategory,
        CreateProduct $createProduct,
        GenerateProductVariants $generateVariants,
    ): void {
        $this->seedTree($createCategory);
        $this->seedAttributes();

        foreach (self::PRODUCTS as $index => [$categoryName, $name, $price, $weight, $featured]) {
            if (Product::withTrashed()->where('name', $name)->exists()) {
                continue;
            }

            $categoryId = Category::query()->where('name', $categoryName)->value('id');

            if ($categoryId === null) {
                continue;
            }

            $createProduct->handle([
                'category_id' => $categoryId,
                'name' => $name,
                'type' => array_key_exists($name, self::VARIANT_GRIDS) ? ProductType::Variable : ProductType::Simple,
                'short_description' => "{$name} pilihan, siap dikirim dari cabang terdekat.",
                'description' => $this->descriptionFor($name),
                'base_price' => $price,
                'cost_price' => (int) round($price * 0.68, -2),
                'weight_gram' => $weight,
                'is_active' => true,
                'is_featured' => $featured,
                'published_at' => now(),
                'sku' => $this->skuFor($name, self::SKU_OFFSET + $index + 1),
            ]);
        }

        $this->seedVariantGrids($generateVariants);
    }

    private function seedTree(CreateCategory $createCategory): void
    {
        foreach (array_keys(self::TREE) as $rootPosition => $rootName) {
            $root = $this->category($createCategory, $rootName, null, $rootPosition);

            foreach (array_keys(self::TREE[$rootName]) as $childPosition => $childName) {
                $child = $this->category($createCategory, $childName, $root->getKey(), $childPosition);

                foreach (self::TREE[$rootName][$childName] as $leafPosition => $leafName) {
                    $this->category($createCategory, $leafName, $child->getKey(), $leafPosition);
                }
            }
        }
    }

    /**
     * The category with this name under this parent, created when missing.
     */
    private function category(CreateCategory $createCategory, string $name, ?int $parentId, int $sortOrder): Category
    {
        $existing = Category::withTrashed()->where('name', $name)->where('parent_id', $parentId)->first();

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

    private function seedAttributes(): void
    {
        foreach (self::ATTRIBUTES as $name => [$type, $values]) {
            $attribute = ProductAttribute::query()->firstOrCreate(
                ['name' => $name],
                ['type' => $type, 'sort_order' => ProductAttribute::query()->count()],
            );

            foreach ($values as $value => $colorHex) {
                $attribute->values()->firstOrCreate(
                    ['value' => $value],
                    ['color_hex' => $colorHex, 'sort_order' => $attribute->values()->count()],
                );
            }
        }
    }

    /**
     * Generates each variable product's grid through the same Action the
     * variant editor uses, skipping a product that already has its grid.
     */
    private function seedVariantGrids(GenerateProductVariants $generateVariants): void
    {
        foreach (self::VARIANT_GRIDS as $productName => $grid) {
            $product = Product::query()->where('name', $productName)->withCount('variants')->first();

            if (! $product instanceof Product || $product->variants_count > 1) {
                continue;
            }

            $groups = [];

            foreach ($grid as $attributeName => $valueNames) {
                $attribute = ProductAttribute::query()->where('name', $attributeName)->firstOrFail();

                $groups[] = array_map(
                    fn (string $valueName): int => (int) $attribute->values()
                        ->where('value', $valueName)
                        ->firstOrFail()
                        ->getKey(),
                    $valueNames,
                );
            }

            $generateVariants->handle($product, $groups);
        }
    }

    private function descriptionFor(string $name): string
    {
        $faker = fake('id_ID');

        return "{$name} dibuat untuk pemakaian sehari-hari dengan kualitas yang terjaga.\n\n"
            .'Keunggulan: '.implode(', ', $faker->randomElements([
                'bahan awet', 'garansi resmi', 'mudah dibersihkan', 'ringan dibawa',
                'desain modern', 'hemat energi', 'kemasan aman', 'cocok untuk hadiah',
            ], 3)).".\n\n"
            .'Stok tersedia di cabang Jakarta, Bandung, dan Surabaya — pilih cabang terdekat untuk ambil sendiri atau kirim.';
    }

    /**
     * A readable SKU from the product's initials and its number, e.g.
     * "ETB-101". Non-letters are dropped so "Kopi & Teh" does not produce
     * an ampersand in a SKU.
     */
    private function skuFor(string $name, int $number): string
    {
        $initials = collect(preg_split('/\s+/', preg_replace('/[^\pL\s]/u', '', $name) ?? $name) ?: [])
            ->filter()
            ->take(3)
            ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
            ->implode('');

        return $initials.'-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }
}
