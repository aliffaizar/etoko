<?php

namespace App\Http\Requests\Branch;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A by-hand correction to one warehouse's figure (P1-E4-T2).
 *
 * The change is **relative and signed**, because that is what the person
 * actually knows: "two more arrived than the paperwork said", "one was
 * broken". A new total is what a stock opname produces, against the figure the
 * system held at the moment of counting (P1-E4-T7).
 *
 * A note is required, not optional. A figure that moved for no recorded reason
 * is exactly the thing the ledger exists to prevent.
 */
class AdjustStockRequest extends FormRequest
{
    /** Largest single adjustment accepted, to keep a slipped keystroke out. */
    public const MAX_CHANGE = 1_000_000;

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
            'quantity_change' => ['required', 'integer', 'min:-'.self::MAX_CHANGE, 'max:'.self::MAX_CHANGE],
            'notes' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->refuseNoChange($validator)];
    }

    /**
     * An adjustment of zero would write a ledger row that explains nothing.
     */
    private function refuseNoChange(Validator $validator): void
    {
        if ($validator->errors()->has('quantity_change') || $this->integer('quantity_change') !== 0) {
            return;
        }

        $validator->errors()->add(
            'quantity_change',
            'Penyesuaian harus menambah atau mengurangi stok. Isi angka positif untuk menambah, negatif untuk mengurangi.',
        );
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'warehouse_id' => 'gudang',
            'quantity_change' => 'penyesuaian',
            'notes' => 'alasan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'notes.required' => 'Alasan penyesuaian wajib diisi, supaya perubahan stok ini bisa dijelaskan nanti.',
        ];
    }
}
