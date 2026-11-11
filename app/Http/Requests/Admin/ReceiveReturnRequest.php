<?php

namespace App\Http\Requests\Admin;

use App\Enums\ReturnItemCondition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The count taken when a returned parcel is opened (P2-E4-T3).
 *
 * One entry per line, each saying what condition the goods are actually in and
 * whether those units go back on sale. This is the branch's count, not the
 * customer's claim: a customer may have said "rusak" and the branch may find
 * them fine, and it is the branch that decides the shelf.
 *
 * Lines are keyed by `return_items.id` rather than by position, so a stale
 * form cannot line up with the wrong goods. A line the form leaves out keeps
 * what the customer asked for, and a line naming something that is not part of
 * this request is dropped by `ReceiveReturn` rather than refused — the ids
 * come from a browser, and a stale form is not a reason to turn a parcel away
 * at the counter.
 */
class ReceiveReturnRequest extends FormRequest
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
            'items' => ['nullable', 'array'],
            'items.*.id' => ['required', 'integer'],
            'items.*.condition' => ['required', 'string', Rule::enum(ReturnItemCondition::class)],
            'items.*.restock' => ['required', 'boolean'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'items' => 'rincian barang',
            'notes' => 'catatan penerimaan',
        ];
    }

    /**
     * The counts as `ReceiveReturn` takes them.
     *
     * @return array<int, array{id: int, condition: ReturnItemCondition, restock: bool, notes: string|null}>
     */
    public function lines(): array
    {
        /** @var array<int, array{id: int|string, condition: string, restock: bool|string, notes: string|null}> $items */
        $items = $this->validated('items') ?? [];

        return array_values(array_map(fn (array $item): array => [
            'id' => (int) $item['id'],
            'condition' => ReturnItemCondition::from((string) $item['condition']),
            'restock' => (bool) $item['restock'],
            'notes' => trim((string) ($item['notes'] ?? '')) === ''
                ? null
                : trim((string) $item['notes']),
        ], $items));
    }

    public function notes(): ?string
    {
        $notes = trim((string) $this->validated('notes'));

        return $notes === '' ? null : $notes;
    }
}
