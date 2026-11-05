<?php

namespace App\Http\Requests\Admin;

use App\Models\ProductAttributeValue;
use App\Support\AttributeCombinations;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The options a product is to be varied by (P1-E3-T6).
 *
 * The payload is one group per attribute — `options[0][attribute_id]` with its
 * `value_ids` — because the order of the groups decides the order of the
 * generated names: Warna before Ukuran reads "Merah / XL", the other way round
 * reads "XL / Merah".
 */
class GenerateProductVariantsRequest extends FormRequest
{
    /**
     * The route carries `can:products.update` and the controller asks
     * `ProductPolicy::update`, which additionally demands a central actor
     * (BR-1).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'options' => ['required', 'array', 'min:1', 'max:5'],
            'options.*.attribute_id' => ['required', 'integer', 'exists:product_attributes,id', 'distinct'],
            'options.*.value_ids' => ['required', 'array', 'min:1'],
            'options.*.value_ids.*' => ['integer', 'exists:product_attribute_values,id', 'distinct'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->requireValuesToBelongToTheirAttribute($validator),
            fn (Validator $validator) => $this->refuseAnUnmanageableGrid($validator),
        ];
    }

    /**
     * The option groups, as `GenerateProductVariants` wants them: value ids
     * only, one inner list per attribute, in the submitted order.
     *
     * @return array<int, array<int, int>>
     */
    public function valueIdGroups(): array
    {
        return array_values(array_map(
            fn (array $option): array => array_values(array_map('intval', $option['value_ids'])),
            $this->optionGroups(),
        ));
    }

    /**
     * A value picked under the wrong attribute would silently produce a
     * nonsense combination — "Merah / Merah" under Warna and Ukuran — so the
     * pairing is checked rather than trusted.
     */
    private function requireValuesToBelongToTheirAttribute(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        foreach ($this->optionGroups() as $index => $option) {
            $attributeId = (int) $option['attribute_id'];
            $valueIds = array_map('intval', $option['value_ids']);

            $belonging = ProductAttributeValue::query()
                ->whereIn('id', $valueIds)
                ->where('product_attribute_id', $attributeId)
                ->count();

            if ($belonging === count($valueIds)) {
                continue;
            }

            $validator->errors()->add(
                "options.{$index}.value_ids",
                'Ada nilai yang bukan milik atribut yang dipilih.',
            );
        }
    }

    /**
     * Every generated variant needs its own SKU, price and stock, so a grid
     * nobody could maintain is refused before it is built.
     */
    private function refuseAnUnmanageableGrid(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $total = AttributeCombinations::count($this->valueIdGroups());

        if ($total <= AttributeCombinations::MAX_COMBINATIONS) {
            return;
        }

        $validator->errors()->add('options', sprintf(
            'Pilihan ini menghasilkan %d kombinasi, melewati batas %d varian per produk. Kurangi jumlah atribut atau nilainya.',
            $total,
            AttributeCombinations::MAX_COMBINATIONS,
        ));
    }

    /**
     * @return array<int, array{attribute_id: int|string, value_ids: array<int, int|string>}>
     */
    private function optionGroups(): array
    {
        /** @var array<int, array{attribute_id: int|string, value_ids: array<int, int|string>}> $options */
        $options = $this->input('options', []);

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'options' => 'pilihan atribut',
            'options.*.attribute_id' => 'atribut',
            'options.*.value_ids' => 'nilai atribut',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'options.required' => 'Pilih minimal satu atribut beserta nilainya untuk membuat varian.',
            'options.*.attribute_id.distinct' => 'Atribut yang sama tidak boleh dipilih dua kali.',
            'options.*.value_ids.required' => 'Pilih minimal satu nilai untuk setiap atribut.',
        ];
    }
}
