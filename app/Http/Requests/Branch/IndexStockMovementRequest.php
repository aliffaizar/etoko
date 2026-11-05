<?php

namespace App\Http\Requests\Branch;

use App\Enums\StockMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The movement history's query string (P1-E4-T8).
 *
 * There is no sort: a ledger is read in the order it happened, newest first,
 * and letting it be reordered would invite reading `quantity_before` and
 * `quantity_after` as a sequence they are not.
 */
class IndexStockMovementRequest extends FormRequest
{
    /**
     * Authorisation is the route's `can:stocks.view` middleware plus
     * `BranchPolicy::viewStock` in the controller.
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
            'branch' => ['nullable', 'integer', 'exists:branches,id'],
            'warehouse' => ['nullable', 'integer', 'exists:warehouses,id'],
            'type' => ['nullable', 'string', Rule::enum(StockMovementType::class)],
            'from' => ['nullable', 'date'],
            'until' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{type: string, from: string, until: string}
     */
    public function filters(): array
    {
        /** @var array<string, string|int|null> $validated */
        $validated = $this->validated();

        return [
            'type' => (string) ($validated['type'] ?? ''),
            'from' => (string) ($validated['from'] ?? ''),
            'until' => (string) ($validated['until'] ?? ''),
        ];
    }

    public function branchId(): ?int
    {
        $value = $this->validated('branch');

        return $value === null ? null : (int) $value;
    }

    public function warehouseId(): ?int
    {
        $value = $this->validated('warehouse');

        return $value === null ? null : (int) $value;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['from' => 'tanggal mulai', 'until' => 'tanggal akhir'];
    }
}
