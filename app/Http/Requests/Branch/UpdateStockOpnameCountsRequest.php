<?php

namespace App\Http\Requests\Branch;

use App\Support\StockDocumentRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The figures found on the shelves (P1-E4-T7).
 *
 * `counted_quantity` is **nullable on purpose**: null is "not counted yet",
 * which is a different finding from a counted zero, and clearing a figure
 * typed by mistake has to stay possible. Sending null is therefore a real
 * instruction rather than a missing field.
 *
 * Only the lines being changed need to be sent, so the form may save a page at
 * a time on a long count.
 */
class UpdateStockOpnameCountsRequest extends FormRequest
{
    /**
     * The route carries `can:stock_opnames.create` and the controller asks
     * `StockOpnamePolicy::recordCounts`.
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
            'items' => ['required', 'array', 'min:1', 'max:'.StockDocumentRules::MAX_ITEMS],
            'items.*.id' => ['required', 'integer', 'exists:stock_opname_items,id'],
            'items.*.counted_quantity' => ['present', 'nullable', 'integer', 'min:0', 'max:'.StockDocumentRules::MAX_QUANTITY],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The lines as `RecordStockOpnameCounts` wants them.
     *
     * @return array<int, array{id: int, counted_quantity: int|null, notes: string|null}>
     */
    public function counts(): array
    {
        /** @var array<int, array{id: int|string, counted_quantity: int|string|null, notes?: string|null}> $items */
        $items = $this->validated('items');

        return array_map(fn (array $item): array => [
            'id' => (int) $item['id'],
            'counted_quantity' => $item['counted_quantity'] === null
                ? null
                : (int) $item['counted_quantity'],
            'notes' => $item['notes'] ?? null,
        ], $items);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'items' => 'hasil hitung',
            'items.*.counted_quantity' => 'jumlah hasil hitung',
            'items.*.notes' => 'catatan baris',
        ];
    }
}
