<?php

namespace App\Support;

use App\Enums\ProductType;
use Illuminate\Validation\Rule;

/**
 * The validation rules for a product's own fields, in one place
 * (P1-E3-T5, P1-E3-T11).
 *
 * The product form and the CSV import both have to accept exactly the same
 * products: a row the panel would refuse must not get in through a spreadsheet,
 * and a row the import accepts must be editable afterwards without the form
 * rejecting it. Keeping the rules here rather than in the form request trait is
 * what makes that true by construction.
 *
 * `ValidatesProductDetails` (HTTP) and `ProductCsvSchema` (import) are both
 * callers; neither owns the rules.
 */
final class CatalogRules
{
    /** The largest rupiah amount a price field accepts, to keep typos out. */
    public const MAX_PRICE = 999_999_999_999;

    /** The longest SKU the column holds. */
    public const SKU_MAX_LENGTH = 60;

    /** The alphabet a SKU may use. */
    public const SKU_PATTERN = '/^[A-Z0-9._-]+$/';

    /**
     * A product's own fields, without the identity checks that depend on which
     * record is being edited.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function productDetails(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(ProductType::class)],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:65535'],

            'base_price' => ['required', 'integer', 'min:0', 'max:'.self::MAX_PRICE],
            'compare_at_price' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_PRICE],
            'cost_price' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_PRICE],

            'weight_gram' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'length_cm' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'width_cm' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'height_cm' => ['nullable', 'integer', 'min:0', 'max:65535'],

            'is_active' => ['boolean'],
            'is_featured' => ['boolean'],
            'published_at' => ['nullable', 'date'],

            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Indonesian field names, used in every message about a product.
     *
     * @return array<string, string>
     */
    public static function productAttributeNames(): array
    {
        return [
            'category_id' => 'kategori',
            'category_slug' => 'kategori',
            'name' => 'nama produk',
            'slug' => 'slug',
            'type' => 'tipe produk',
            'short_description' => 'deskripsi singkat',
            'description' => 'deskripsi',
            'base_price' => 'harga jual',
            'compare_at_price' => 'harga sebelum diskon',
            'cost_price' => 'harga pokok',
            'weight_gram' => 'berat',
            'length_cm' => 'panjang',
            'width_cm' => 'lebar',
            'height_cm' => 'tinggi',
            'is_active' => 'status aktif',
            'is_featured' => 'produk unggulan',
            'published_at' => 'tanggal terbit',
            'meta_title' => 'meta title',
            'meta_description' => 'meta description',
            'sku' => 'SKU',
            'barcode' => 'barcode',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function productMessages(): array
    {
        return [
            'sku.regex' => 'SKU hanya boleh berisi huruf kapital, angka, tanda hubung, titik, dan garis bawah.',
            'base_price.integer' => 'Harga jual harus berupa angka rupiah bulat, tanpa titik atau koma.',
        ];
    }

    /**
     * Whether a strikethrough price actually advertises a real discount.
     *
     * Shared because an import must refuse what the form refuses: a
     * `compare_at_price` at or below the selling price promises a saving that
     * does not exist.
     */
    public static function compareAtPriceIsAboveBasePrice(?int $compareAtPrice, int $basePrice): bool
    {
        return $compareAtPrice === null || $compareAtPrice > $basePrice;
    }
}
