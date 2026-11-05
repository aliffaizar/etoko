<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Switching products on or off for one branch (P1-E3-T9).
 *
 * One endpoint serves the row toggle and the bulk action, because they are the
 * same operation over a different number of products. The ceiling matches a
 * page of the listing plus room to grow, so "select the whole page and switch
 * it off" works while a crafted request cannot ask for the whole catalogue in
 * one transaction.
 */
class UpdateBranchProductAvailabilityRequest extends FormRequest
{
    /** How many products one call may change. */
    public const MAX_PRODUCTS = 200;

    /**
     * The route carries `can:products.update` and the controller asks
     * `BranchPolicy::manageAvailability`, which additionally demands a central
     * actor (BR-1).
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
            'product_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_PRODUCTS],
            'product_ids.*' => ['integer', 'distinct', 'exists:products,id'],
            'is_available' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $notes = trim((string) $this->input('notes'));

        $this->merge(['notes' => $notes === '' ? null : $notes]);
    }

    /**
     * @return array<int, int>
     */
    public function productIds(): array
    {
        /** @var array<int, int|string> $ids */
        $ids = $this->input('product_ids', []);

        return array_values(array_map('intval', $ids));
    }

    public function notes(): ?string
    {
        $notes = $this->input('notes');

        return $notes === null ? null : (string) $notes;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'product_ids' => 'produk',
            'is_available' => 'ketersediaan',
            'notes' => 'catatan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_ids.required' => 'Pilih minimal satu produk.',
            'product_ids.max' => 'Maksimal '.self::MAX_PRODUCTS.' produk per perubahan.',
        ];
    }
}
