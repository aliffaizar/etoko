<?php

namespace App\Concerns;

use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Support\AttributeCombinations;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared validation for one variant of a product (P1-E3-T6).
 *
 * Two rules the database cannot hold are enforced here, both named in
 * `docs/03-data-model.md`:
 *
 * 1. A variant may not carry two values of the same attribute — "Merah / Biru"
 *    is not a variant, it is two.
 * 2. Two variants of one product may not carry the same combination, or a
 *    buyer picking Merah and XL would get whichever row came first.
 *
 * Price, weight and dimensions are overrides, so `nullable` here means
 * "follow the product" and not "unknown" (ADR-020).
 *
 * @phpstan-require-extends FormRequest
 */
trait ValidatesProductVariantDetails
{
    /** The largest rupiah amount a price field accepts, to keep typos out. */
    public const MAX_PRICE = 999_999_999_999;

    /**
     * @param  int|null  $ignoreVariantId  The variant being edited, whose own SKU and barcode do not count as taken.
     * @return array<string, array<int, mixed>>
     */
    protected function variantDetailRules(?int $ignoreVariantId = null): array
    {
        return [
            'sku' => [
                'required', 'string', 'max:60', 'regex:/^[A-Z0-9._-]+$/',
                // Queries the table directly, so a soft-deleted variant keeps
                // its SKU: order history still points at it.
                Rule::unique('product_variants', 'sku')->ignore($ignoreVariantId),
            ],
            'barcode' => [
                'nullable', 'string', 'max:60',
                Rule::unique('product_variants', 'barcode')->ignore($ignoreVariantId),
            ],
            'name' => ['nullable', 'string', 'max:255'],

            'price' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_PRICE],
            'compare_at_price' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_PRICE],
            'cost_price' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_PRICE],

            'weight_gram' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'length_cm' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'width_cm' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'height_cm' => ['nullable', 'integer', 'min:0', 'max:65535'],

            'is_active' => ['boolean'],

            'attribute_value_ids' => ['array', 'max:5'],
            'attribute_value_ids.*' => ['integer', 'exists:product_attribute_values,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function variantDetailAttributes(): array
    {
        return [
            'sku' => 'SKU',
            'barcode' => 'barcode',
            'name' => 'nama varian',
            'price' => 'harga varian',
            'compare_at_price' => 'harga sebelum diskon',
            'cost_price' => 'harga pokok',
            'weight_gram' => 'berat',
            'length_cm' => 'panjang',
            'width_cm' => 'lebar',
            'height_cm' => 'tinggi',
            'is_active' => 'status aktif',
            'attribute_value_ids' => 'pilihan atribut',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function variantDetailMessages(): array
    {
        return [
            'sku.regex' => 'SKU hanya boleh berisi huruf kapital, angka, tanda hubung, titik, dan garis bawah.',
            'sku.unique' => 'SKU ini sudah dipakai varian lain. SKU harus unik di seluruh katalog.',
            'barcode.unique' => 'Barcode ini sudah dipakai varian lain.',
            'price.integer' => 'Harga varian harus berupa angka rupiah bulat, tanpa titik atau koma.',
            'attribute_value_ids.max' => 'Satu varian paling banyak dibentuk oleh 5 atribut.',
        ];
    }

    /**
     * An empty number input arrives as null, which is exactly what an override
     * that follows the product should be — so nothing is normalised to zero
     * here. The SKU is upper-cased to match the column, and a blank barcode
     * becomes null so it does not claim the "no barcode" slot.
     */
    protected function normalizeVariantFields(): void
    {
        $barcode = trim((string) $this->input('barcode'));
        $name = trim((string) $this->input('name'));

        $this->merge([
            'sku' => mb_strtoupper(trim((string) $this->input('sku'))),
            'barcode' => $barcode === '' ? null : $barcode,
            'name' => $name === '' ? null : $name,
        ]);
    }

    /**
     * Holds both combination rules: one value per attribute, and no two
     * variants of a product sharing a combination.
     */
    protected function validateVariantCombination(Validator $validator, Product $product, ?ProductVariant $ignore = null): void
    {
        if ($validator->errors()->has('attribute_value_ids')) {
            return;
        }

        /** @var array<int, int> $valueIds */
        $valueIds = array_map('intval', (array) $this->input('attribute_value_ids', []));

        if ($valueIds === []) {
            return;
        }

        $values = $this->loadAttributeValues($valueIds);

        if ($values->count() !== count(array_unique($valueIds))) {
            return;
        }

        if ($this->hasRepeatedAttribute($values)) {
            $validator->errors()->add(
                'attribute_value_ids',
                'Satu varian hanya boleh punya satu nilai per atribut. Pilih salah satu, atau buat varian terpisah.',
            );

            return;
        }

        if ($this->combinationIsTaken($product, $valueIds, $ignore)) {
            $validator->errors()->add(
                'attribute_value_ids',
                'Produk ini sudah punya varian dengan kombinasi pilihan yang sama.',
            );
        }
    }

    /**
     * @param  array<int, int>  $valueIds
     * @return Collection<int, ProductAttributeValue>
     */
    private function loadAttributeValues(array $valueIds): Collection
    {
        /** @var Collection<int, ProductAttributeValue> $values */
        $values = ProductAttributeValue::query()->whereIn('id', $valueIds)->get();

        return $values;
    }

    /**
     * @param  Collection<int, ProductAttributeValue>  $values
     */
    private function hasRepeatedAttribute(Collection $values): bool
    {
        $attributeIds = $values->pluck('product_attribute_id');

        return $attributeIds->unique()->count() !== $attributeIds->count();
    }

    /**
     * @param  array<int, int>  $valueIds
     */
    private function combinationIsTaken(Product $product, array $valueIds, ?ProductVariant $ignore): bool
    {
        $fingerprint = AttributeCombinations::fingerprint($valueIds);

        return $product->variants()
            ->when($ignore instanceof ProductVariant, fn ($query) => $query->whereKeyNot($ignore?->getKey()))
            ->with('attributeValues:id')
            ->get()
            ->contains(fn (ProductVariant $variant): bool => $variant->combinationFingerprint() === $fingerprint);
    }
}
