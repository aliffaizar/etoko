<?php

namespace App\Concerns;

use App\Enums\ProductAttributeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared validation for a product attribute and its values (P1-E3-T6).
 *
 * Create and edit submit the same fields, so the rules live here once; what
 * differs is the uniqueness check, which has to ignore the record being edited.
 *
 * `color_hex` is only meaningful on a `color` attribute, and a `color`
 * attribute without swatches is pointless — the cross-field rules below hold
 * both ends of that, because the column itself cannot.
 *
 * @phpstan-require-extends FormRequest
 */
trait ValidatesProductAttributeDetails
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function attributeDetailRules(?int $ignoreId = null): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('product_attributes', 'name')->ignore($ignoreId),
            ],
            'type' => ['required', Rule::enum(ProductAttributeType::class)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function attributeValueRules(int $attributeId, ?int $ignoreId = null): array
    {
        return [
            'value' => [
                'required', 'string', 'max:255',
                Rule::unique('product_attribute_values', 'value')
                    ->where('product_attribute_id', $attributeId)
                    ->ignore($ignoreId),
            ],
            'label' => ['nullable', 'string', 'max:255'],
            'color_hex' => ['nullable', 'string', 'regex:/^#[0-9a-f]{6}$/'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function attributeDetailAttributes(): array
    {
        return [
            'name' => 'nama atribut',
            'type' => 'tipe atribut',
            'sort_order' => 'urutan',
            'value' => 'nilai',
            'label' => 'label tampilan',
            'color_hex' => 'kode warna',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function attributeDetailMessages(): array
    {
        return [
            'name.unique' => 'Atribut dengan nama ini sudah ada. Atribut dipakai bersama seluruh katalog, jadi namanya harus unik.',
            'value.unique' => 'Nilai ini sudah ada pada atribut tersebut.',
            'color_hex.regex' => 'Kode warna harus berupa heksadesimal enam digit, misalnya #c62828.',
        ];
    }

    /**
     * A swatch on an attribute that is not a colour would never be drawn, and
     * a colour value with no swatch leaves the storefront nothing to draw.
     */
    protected function requireColorHexOnlyForColorAttributes(Validator $validator, ProductAttributeType $type): void
    {
        if ($validator->errors()->has('color_hex')) {
            return;
        }

        $hex = $this->input('color_hex');
        $hasHex = $hex !== null && $hex !== '';

        if ($type->usesColorSwatch() && ! $hasHex) {
            $validator->errors()->add('color_hex', 'Atribut bertipe warna membutuhkan kode warna untuk setiap nilainya.');

            return;
        }

        if (! $type->usesColorSwatch() && $hasHex) {
            $validator->errors()->add('color_hex', 'Kode warna hanya berlaku untuk atribut bertipe warna.');
        }
    }

    /**
     * `sort_order` is NOT NULL, but an emptied number input arrives as null.
     */
    protected function normalizeSortOrder(): void
    {
        if ($this->input('sort_order') === null || $this->input('sort_order') === '') {
            $this->merge(['sort_order' => 0]);
        }
    }
}
