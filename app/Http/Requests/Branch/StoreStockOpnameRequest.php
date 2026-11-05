<?php

namespace App\Http\Requests\Branch;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A stock count being opened (P1-E4-T7).
 *
 * The session's lines are not submitted: they are built by
 * `CreateStockOpname` from the chosen scope, each snapshotting what the system
 * believes right now. A client-supplied snapshot would be a figure the client
 * could choose, and the whole point of the snapshot is that the system is the
 * one who said it.
 *
 * `category_id` left out means "everything this warehouse holds".
 */
class StoreStockOpnameRequest extends FormRequest
{
    /**
     * The route carries `can:stock_opnames.create` and the controller asks
     * `StockOpnamePolicy::create`.
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
            'counted_at' => ['required', 'date', 'before_or_equal:today'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'warehouse_id' => 'gudang',
            'counted_at' => 'tanggal hitung',
            'category_id' => 'kategori',
            'notes' => 'catatan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'counted_at.before_or_equal' => 'Tanggal hitung tidak boleh di masa depan.',
        ];
    }
}
