<?php

namespace App\Support;

use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The CSV format products are exported in and imported from (P1-E3-T11).
 *
 * Export and import read the same column list, so a file that comes out of one
 * goes back into the other — which is the whole point: the normal way to load
 * a catalogue is to export the empty template, fill it in a spreadsheet, and
 * import it back.
 *
 * `sku` is the business key. A row whose SKU already belongs to a variant
 * updates that variant's product; a row with a new SKU creates a product. That
 * makes an import re-runnable: fixing three cells and importing the same file
 * again changes three products rather than adding the whole file twice.
 *
 * The category is named by slug rather than by id, because an id means nothing
 * in a spreadsheet, and categories are created on their own screen — an import
 * files products under categories that exist, it does not invent taxonomy.
 */
final class ProductCsvSchema
{
    /**
     * The columns, in order. The header row of an export is exactly this.
     *
     * @var array<int, string>
     */
    public const COLUMNS = [
        'sku',
        'barcode',
        'name',
        'slug',
        'category_slug',
        'type',
        'base_price',
        'compare_at_price',
        'cost_price',
        'weight_gram',
        'length_cm',
        'width_cm',
        'height_cm',
        'short_description',
        'is_active',
        'is_featured',
        'published_at',
        'meta_title',
        'meta_description',
    ];

    /**
     * Columns a row cannot do without.
     *
     * `description` is deliberately not a CSV column at all: it holds HTML,
     * and HTML in a spreadsheet cell is neither readable nor safely
     * round-tripped. It stays editable on the product form.
     *
     * @var array<int, string>
     */
    public const REQUIRED_COLUMNS = ['sku', 'name', 'category_slug', 'base_price'];

    /** How many data rows one import may carry. */
    public const MAX_ROWS = 2000;

    /**
     * Values accepted for a boolean column, so a spreadsheet's "TRUE", "1" and
     * "ya" all work.
     *
     * Two lists rather than one map: a map keyed by '1' and '0' would hold
     * integer keys, because PHP casts numeric strings used as array keys —
     * and then a lookup is subtly not the string comparison it reads as.
     *
     * @var array<int, string>
     */
    private const TRUTHY = ['1', 'true', 'ya', 'yes', 'y', 'aktif'];

    /**
     * @var array<int, string>
     */
    private const FALSY = ['0', 'false', 'tidak', 'no', 'n', 'nonaktif'];

    /**
     * One exported row for one product, keyed by column.
     *
     * The SKU and barcode come from the product's default variant, which is
     * what the product form owns too (ADR-020). Other variants are the variant
     * editor's business and are not in this file.
     *
     * @return array<string, string>
     */
    public static function rowFor(Product $product): array
    {
        $variant = $product->defaultVariant;

        return [
            'sku' => $variant instanceof ProductVariant ? $variant->sku : '',
            'barcode' => $variant instanceof ProductVariant ? (string) $variant->barcode : '',
            'name' => $product->name,
            'slug' => $product->slug,
            'category_slug' => $product->category->slug,
            'type' => $product->type->value,
            'base_price' => (string) $product->base_price,
            'compare_at_price' => (string) $product->compare_at_price,
            'cost_price' => (string) $product->cost_price,
            'weight_gram' => (string) $product->weight_gram,
            'length_cm' => (string) $product->length_cm,
            'width_cm' => (string) $product->width_cm,
            'height_cm' => (string) $product->height_cm,
            'short_description' => (string) $product->short_description,
            'is_active' => $product->is_active ? '1' : '0',
            'is_featured' => $product->is_featured ? '1' : '0',
            'published_at' => $product->published_at?->format('Y-m-d H:i:s') ?? '',
            'meta_title' => (string) $product->meta_title,
            'meta_description' => (string) $product->meta_description,
        ];
    }

    /**
     * An example row, so the downloaded template shows the expected shape
     * rather than only its headers.
     *
     * @return array<string, string>
     */
    public static function exampleRow(): array
    {
        return [
            'sku' => 'KPS-16-PUTIH',
            'barcode' => '8991102000123',
            'name' => 'Kipas Angin Berdiri 16 Inci',
            'slug' => '',
            'category_slug' => 'pendingin-ruangan',
            'type' => ProductType::Simple->value,
            'base_price' => '250000',
            'compare_at_price' => '300000',
            'cost_price' => '180000',
            'weight_gram' => '3500',
            'length_cm' => '40',
            'width_cm' => '40',
            'height_cm' => '120',
            'short_description' => 'Kipas hemat listrik dengan tiga kecepatan.',
            'is_active' => '1',
            'is_featured' => '0',
            'published_at' => '2026-11-01 08:00:00',
            'meta_title' => '',
            'meta_description' => '',
        ];
    }

    /**
     * Turns one raw CSV row into product attributes, or into the messages
     * explaining why it cannot be one.
     *
     * The validation rules come from `CatalogRules`, the same source the
     * product form reads, plus the identity checks that only make sense per
     * row: the category has to exist, and a slug or barcode may not be taken
     * by a *different* product.
     *
     * @param  array<string, string>  $row  Raw values, keyed by column.
     * @return array{attributes: array<string, mixed>|null, errors: array<int, string>}
     */
    public static function parseRow(array $row): array
    {
        $sku = mb_strtoupper(trim($row['sku'] ?? ''));
        $existingVariant = $sku === '' ? null : ProductVariant::withTrashed()->where('sku', $sku)->first();
        $productId = $existingVariant?->product_id;

        $candidate = self::normalise($row, $sku);

        $validator = Validator::make(
            $candidate,
            self::rulesFor($productId, $existingVariant?->getKey()),
            CatalogRules::productMessages(),
            CatalogRules::productAttributeNames(),
        );

        $validator->after(function ($validator) use ($candidate): void {
            if ($validator->errors()->hasAny(['base_price', 'compare_at_price'])) {
                return;
            }

            $compareAt = $candidate['compare_at_price'];

            if (CatalogRules::compareAtPriceIsAboveBasePrice(
                $compareAt === null ? null : (int) $compareAt,
                (int) $candidate['base_price'],
            )) {
                return;
            }

            $validator->errors()->add(
                'compare_at_price',
                'Harga sebelum diskon harus lebih tinggi dari harga jual, atau dibiarkan kosong.',
            );
        });

        if ($validator->fails()) {
            return ['attributes' => null, 'errors' => $validator->errors()->all()];
        }

        return ['attributes' => self::toAttributes($candidate), 'errors' => []];
    }

    /**
     * Rules for one row: the shared product rules, with `category_id` replaced
     * by the slug the file actually carries and the identity checks added.
     *
     * @return array<string, array<int, mixed>>
     */
    private static function rulesFor(?int $productId, ?int $variantId): array
    {
        $rules = CatalogRules::productDetails();
        unset($rules['category_id'], $rules['description']);

        return [
            ...$rules,
            'category_slug' => ['required', 'string', Rule::exists('categories', 'slug')],
            'sku' => [
                'required', 'string', 'max:'.CatalogRules::SKU_MAX_LENGTH,
                'regex:'.CatalogRules::SKU_PATTERN,
            ],
            'barcode' => [
                'nullable', 'string', 'max:60',
                Rule::unique('product_variants', 'barcode')->ignore($variantId),
            ],
            'slug' => [
                'nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('products', 'slug')->ignore($productId),
            ],
        ];
    }

    /**
     * Trims every cell and turns the empty ones into null, so a blank
     * spreadsheet cell means "not set" rather than an empty string that
     * `nullable` would let through as `''`.
     *
     * The two NOT NULL numbers default to 0 the same way the product form
     * normalises them, so a blank cell is not an error about a field nobody
     * filled in.
     *
     * @param  array<string, string>  $row
     * @return array<string, string|int|bool|null>
     */
    private static function normalise(array $row, string $sku): array
    {
        $value = function (string $column) use ($row): ?string {
            $raw = trim((string) ($row[$column] ?? ''));

            return $raw === '' ? null : $raw;
        };

        return [
            'sku' => $sku,
            'barcode' => $value('barcode'),
            'name' => $value('name'),
            'slug' => $value('slug') === null ? null : mb_strtolower((string) $value('slug')),
            'category_slug' => $value('category_slug'),
            'type' => $value('type') ?? ProductType::Simple->value,
            'base_price' => $value('base_price') ?? '0',
            'compare_at_price' => $value('compare_at_price'),
            'cost_price' => $value('cost_price'),
            'weight_gram' => $value('weight_gram') ?? '0',
            'length_cm' => $value('length_cm'),
            'width_cm' => $value('width_cm'),
            'height_cm' => $value('height_cm'),
            'short_description' => $value('short_description'),
            'is_active' => self::toBoolean($value('is_active'), true),
            'is_featured' => self::toBoolean($value('is_featured'), false),
            'published_at' => $value('published_at'),
            'meta_title' => $value('meta_title'),
            'meta_description' => $value('meta_description'),
        ];
    }

    /**
     * The validated row as the Actions want it: `category_id` resolved from the
     * slug, numbers cast, dates parsed.
     *
     * @param  array<string, string|int|bool|null>  $candidate
     * @return array<string, mixed>
     */
    private static function toAttributes(array $candidate): array
    {
        $integer = fn (string $key): ?int => $candidate[$key] === null ? null : (int) $candidate[$key];

        return [
            'category_id' => (int) Category::query()->where('slug', $candidate['category_slug'])->value('id'),
            'name' => (string) $candidate['name'],
            'slug' => $candidate['slug'],
            'type' => (string) $candidate['type'],
            'short_description' => $candidate['short_description'],
            'base_price' => (int) $candidate['base_price'],
            'compare_at_price' => $integer('compare_at_price'),
            'cost_price' => $integer('cost_price'),
            'weight_gram' => (int) $candidate['weight_gram'],
            'length_cm' => $integer('length_cm'),
            'width_cm' => $integer('width_cm'),
            'height_cm' => $integer('height_cm'),
            'is_active' => (bool) $candidate['is_active'],
            'is_featured' => (bool) $candidate['is_featured'],
            'published_at' => $candidate['published_at'] === null
                ? null
                : Carbon::parse((string) $candidate['published_at']),
            'meta_title' => $candidate['meta_title'],
            'meta_description' => $candidate['meta_description'],
            'sku' => (string) $candidate['sku'],
            'barcode' => $candidate['barcode'],
        ];
    }

    /**
     * Reads a spreadsheet's idea of a boolean.
     */
    private static function toBoolean(?string $raw, bool $default): bool
    {
        if ($raw === null) {
            return $default;
        }

        $value = mb_strtolower($raw);

        if (in_array($value, self::TRUTHY, true)) {
            return true;
        }

        if (in_array($value, self::FALSY, true)) {
            return false;
        }

        return $default;
    }
}
