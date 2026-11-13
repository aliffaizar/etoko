<?php

namespace App\Http\Requests\Branch;

use App\Support\StockDocumentRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The per-line figures of an approve, ship or receive step (P2-E3-T3, T4).
 *
 * One request for the three, because each says the same thing — a quantity
 * for every line — and differs only in what bounds it. The bound is the
 * figure of the step before, which only the locked row knows, so
 * `StockTransferLines` checks it in the action; here the shape is checked.
 *
 * `from_warehouse_id` is only read by approval, `receipt_notes` only by
 * receipt.
 */
class AdvanceStockTransferRequest extends FormRequest
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
        return [
            'items' => ['required', 'array', 'min:1', 'max:'.StockDocumentRules::MAX_ITEMS],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:0', 'max:'.StockDocumentRules::MAX_QUANTITY],
            'from_warehouse_id' => ['nullable', 'integer'],
            'receipt_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'items' => 'baris barang',
            'items.*.quantity' => 'jumlah',
            'from_warehouse_id' => 'gudang asal',
            'receipt_notes' => 'catatan penerimaan',
        ];
    }

    /**
     * @return array<int, array{id: int, quantity: int}>
     */
    public function lines(): array
    {
        /** @var array<int, array{id: int|string, quantity: int|string}> $items */
        $items = $this->validated('items');

        return array_map(fn (array $item): array => [
            'id' => (int) $item['id'],
            'quantity' => (int) $item['quantity'],
        ], array_values($items));
    }

    public function fromWarehouseId(): ?int
    {
        $value = $this->validated('from_warehouse_id');

        return $value === null ? null : (int) $value;
    }

    public function receiptNotes(): ?string
    {
        $notes = trim((string) ($this->validated('receipt_notes') ?? ''));

        return $notes === '' ? null : $notes;
    }
}
