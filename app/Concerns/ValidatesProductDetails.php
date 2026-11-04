<?php

namespace App\Concerns;

use App\Enums\ProductType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared validation for a product's own fields (P1-E3-T5).
 *
 * Create and edit submit the same fields, so the rules live here once. What
 * differs is the slug (derived on create) and the uniqueness checks, which
 * have to ignore the record being edited.
 *
 * Prices arrive as whole rupiah integers (ADR-008, BR-10): the form sends
 * digits, never a decimal, and the columns are `unsignedBigInteger`.
 *
 * @phpstan-require-extends FormRequest
 */
trait ValidatesProductDetails
{
    /** The largest rupiah amount a price field accepts, to keep typos out. */
    public const MAX_PRICE = 999_999_999_999;

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function productDetailRules(): array
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
     * @return array<string, string>
     */
    protected function productDetailAttributes(): array
    {
        return [
            'category_id' => 'kategori',
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
    protected function productDetailMessages(): array
    {
        return [
            'sku.regex' => 'SKU hanya boleh berisi huruf kapital, angka, tanda hubung, titik, dan garis bawah.',
            'base_price.integer' => 'Harga jual harus berupa angka rupiah bulat, tanpa titik atau koma.',
        ];
    }

    /**
     * A strikethrough price that is not actually higher than the selling price
     * advertises a discount that does not exist.
     */
    protected function requireCompareAtPriceAboveBasePrice(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['base_price', 'compare_at_price'])) {
            return;
        }

        $compareAt = $this->input('compare_at_price');

        if ($compareAt === null || $compareAt === '') {
            return;
        }

        if ((int) $compareAt > $this->integer('base_price')) {
            return;
        }

        $validator->errors()->add(
            'compare_at_price',
            'Harga sebelum diskon harus lebih tinggi dari harga jual, atau dibiarkan kosong.',
        );
    }

    /**
     * Prices and dimensions are NOT NULL or constrained in the database, but an
     * emptied number input arrives as null (Laravel converts empty strings).
     * The required ones are normalised to a usable default rather than refused
     * with a message about a field the user never filled in; the optional ones
     * stay null.
     */
    protected function normalizeProductNumbers(): void
    {
        foreach (['base_price' => 0, 'weight_gram' => 0] as $field => $default) {
            if ($this->input($field) === null || $this->input($field) === '') {
                $this->merge([$field => $default]);
            }
        }
    }
}
