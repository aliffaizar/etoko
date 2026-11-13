<?php

namespace App\Http\Requests\Branch;

use App\Support\StockDocumentRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request for stock from another branch (P2-E3-T3).
 *
 * The lines follow the same rules as every other stock document. Whether the
 * origin is a different, active branch with a warehouse is the action's to
 * answer, because those are facts about the branch rather than about the form.
 */
class StoreStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $lineRules = StockDocumentRules::items();
        unset($lineRules['items.*.unit_cost']);

        return [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'from_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            ...$lineRules,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'warehouse_id' => 'gudang tujuan',
            'from_branch_id' => 'cabang asal',
            'notes' => 'catatan',
            ...StockDocumentRules::itemAttributes(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return StockDocumentRules::itemMessages();
    }

    /**
     * @return array{notes: string|null, items: array<int, array{product_variant_id: int, quantity: int}>}
     */
    public function transferAttributes(): array
    {
        /** @var array<int, array{product_variant_id: int|string, quantity: int|string}> $items */
        $items = $this->validated('items');

        return [
            'notes' => $this->validated('notes'),
            'items' => array_map(fn (array $item): array => [
                'product_variant_id' => (int) $item['product_variant_id'],
                'quantity' => (int) $item['quantity'],
            ], array_values($items)),
        ];
    }
}
