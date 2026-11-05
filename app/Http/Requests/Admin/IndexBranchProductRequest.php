<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The branch availability listing's query string (P1-E3-T9).
 *
 * The sort column is whitelisted here rather than in the controller so an
 * arbitrary column can never reach `orderBy`.
 */
class IndexBranchProductRequest extends FormRequest
{
    /** Columns the listing may be ordered by. */
    public const SORTABLE_COLUMNS = ['name', 'base_price', 'created_at'];

    /** Values of the availability filter. */
    public const AVAILABILITY_VALUES = ['available', 'unavailable'];

    /**
     * Authorisation is the route's `can:products.view` middleware plus
     * `BranchPolicy::viewCatalog` in the controller.
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
            'availability' => ['nullable', 'string', Rule::in(self::AVAILABILITY_VALUES)],
            'sort' => ['nullable', 'string', Rule::in(self::SORTABLE_COLUMNS)],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{search: string, category: string, availability: string, sort: string, direction: string}
     */
    public function filters(): array
    {
        /** @var array<string, string|int|null> $validated */
        $validated = $this->validated();

        return [
            'search' => trim((string) ($validated['search'] ?? '')),
            'category' => (string) ($validated['category'] ?? ''),
            'availability' => (string) ($validated['availability'] ?? ''),
            'sort' => (string) ($validated['sort'] ?? 'name'),
            'direction' => (string) ($validated['direction'] ?? 'asc'),
        ];
    }
}
