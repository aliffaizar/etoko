<?php

namespace App\Http\Requests\Branch;

use App\Support\StockDocumentRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A goods-in document being drafted or edited (P1-E4-T5).
 *
 * `warehouse_id` is only read when the document is created: a posted document
 * cannot move warehouses, and a drafted one that could would mean the lines
 * were typed against one warehouse's shelves and applied to another's. The
 * controller ignores it on update for that reason.
 *
 * `entry_date` may be backdated — a delivery note is often typed up the next
 * morning — but not postdated, because goods that have not arrived cannot be
 * received.
 */
class StoreStockEntryRequest extends FormRequest
{
    /**
     * The route carries the permission middleware and the controller asks
     * `StockEntryPolicy`.
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
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'entry_date' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
            ...StockDocumentRules::items(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'warehouse_id' => 'gudang',
            'supplier_name' => 'nama supplier',
            'reference_no' => 'nomor referensi',
            'entry_date' => 'tanggal masuk',
            'notes' => 'catatan',
            ...StockDocumentRules::itemAttributes(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'entry_date.before_or_equal' => 'Tanggal masuk tidak boleh di masa depan: barang yang belum tiba belum bisa diterima.',
            ...StockDocumentRules::itemMessages(),
        ];
    }
}
