<?php

namespace App\Http\Requests\Branch;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Reorder thresholds for a batch of variants in one warehouse (P1-E4-T4).
 *
 * Submitted for many variants at once because that is how it is used: a
 * warehouse sets the same minimum for a whole category in one sitting.
 *
 * `max_quantity` and `shelf_location` are nullable on purpose — sending an
 * empty value clears them — while `min_quantity` is required, since the whole
 * point of the form is to say what "low" means here.
 */
class UpdateStockThresholdsRequest extends FormRequest
{
    /** Largest minimum accepted, to keep a slipped keystroke out. */
    public const MAX_QUANTITY = 1_000_000;

    /** Variants one submission may cover, matching the listing's page size many times over. */
    public const MAX_VARIANTS = 500;

    /**
     * The route carries `can:stocks.adjust` and the controller asks
     * `BranchPolicy::adjustStock` for the branch being touched.
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
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'variant_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_VARIANTS],
            'variant_ids.*' => ['integer', 'exists:product_variants,id'],
            'min_quantity' => ['required', 'integer', 'min:0', 'max:'.self::MAX_QUANTITY],
            'max_quantity' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_QUANTITY],
            'shelf_location' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->requireMaximumAboveMinimum($validator)];
    }

    /**
     * A maximum below the minimum could never both be satisfied, and would do
     * so silently.
     */
    private function requireMaximumAboveMinimum(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['min_quantity', 'max_quantity'])) {
            return;
        }

        $maximum = $this->input('max_quantity');

        if ($maximum === null || $maximum === '' || (int) $maximum >= $this->integer('min_quantity')) {
            return;
        }

        $validator->errors()->add(
            'max_quantity',
            'Stok maksimum tidak boleh lebih kecil dari stok minimum.',
        );
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'warehouse_id' => 'gudang',
            'variant_ids' => 'varian',
            'min_quantity' => 'stok minimum',
            'max_quantity' => 'stok maksimum',
            'shelf_location' => 'lokasi rak',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'variant_ids.required' => 'Pilih minimal satu varian terlebih dahulu.',
        ];
    }
}
