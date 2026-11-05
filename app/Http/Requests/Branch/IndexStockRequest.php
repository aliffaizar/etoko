<?php

namespace App\Http\Requests\Branch;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The branch stock listing's query string (P1-E4-T3).
 *
 * `branch` is only honoured for a central actor; `BranchWorkspace` is what
 * ignores it for branch staff, who have exactly one branch (BR-3).
 */
class IndexStockRequest extends FormRequest
{
    /** Columns the listing may be ordered by. */
    public const SORTABLE_COLUMNS = ['sku', 'stock_quantity', 'stock_available'];

    /** Values of the stock-state filter. */
    public const STATE_VALUES = ['low', 'out', 'in_stock'];

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
            'search' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'integer', 'exists:categories,id'],
            'state' => ['nullable', 'string', Rule::in(self::STATE_VALUES)],
            'sort' => ['nullable', 'string', Rule::in(self::SORTABLE_COLUMNS)],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{search: string, category: string, state: string, sort: string, direction: string}
     */
    public function filters(): array
    {
        /** @var array<string, string|int|null> $validated */
        $validated = $this->validated();

        return [
            'search' => trim((string) ($validated['search'] ?? '')),
            'category' => (string) ($validated['category'] ?? ''),
            'state' => (string) ($validated['state'] ?? ''),
            'sort' => (string) ($validated['sort'] ?? 'sku'),
            'direction' => (string) ($validated['direction'] ?? 'asc'),
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
}
