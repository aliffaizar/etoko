<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The branch price listing's query string (P1-E3-T10).
 *
 * Listed per variant, because that is the grain a price is charged at.
 */
class IndexBranchProductPriceRequest extends FormRequest
{
    /** Columns the listing may be ordered by. */
    public const SORTABLE_COLUMNS = ['sku', 'created_at'];

    /** Values of the "has a branch price" filter. */
    public const PRICING_VALUES = ['custom', 'catalogue'];

    /**
     * Authorisation is the route's `can:branch_prices.view` middleware plus
     * `BranchPolicy::viewPrices` in the controller.
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
            'search' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'integer', 'exists:categories,id'],
            'pricing' => ['nullable', 'string', Rule::in(self::PRICING_VALUES)],
            'sort' => ['nullable', 'string', Rule::in(self::SORTABLE_COLUMNS)],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{search: string, category: string, pricing: string, sort: string, direction: string}
     */
    public function filters(): array
    {
        /** @var array<string, string|int|null> $validated */
        $validated = $this->validated();

        return [
            'search' => trim((string) ($validated['search'] ?? '')),
            'category' => (string) ($validated['category'] ?? ''),
            'pricing' => (string) ($validated['pricing'] ?? ''),
            'sort' => (string) ($validated['sort'] ?? 'sku'),
            'direction' => (string) ($validated['direction'] ?? 'asc'),
        ];
    }
}
