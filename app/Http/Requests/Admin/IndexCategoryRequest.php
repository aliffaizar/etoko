<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates and normalises the category listing's query string.
 *
 * The sort column is whitelisted here rather than in the controller so an
 * arbitrary column can never reach `orderBy`.
 */
class IndexCategoryRequest extends FormRequest
{
    /** Columns the listing may be ordered by. */
    public const SORTABLE_COLUMNS = ['name', 'slug', 'sort_order', 'created_at'];

    /** Values of the status filter. */
    public const STATUS_VALUES = ['active', 'inactive'];

    /**
     * Authorisation is the route's `can:categories.view` middleware plus
     * `CategoryPolicy::viewAny` in the controller.
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
            'status' => ['nullable', 'string', Rule::in(self::STATUS_VALUES)],
            'sort' => ['nullable', 'string', Rule::in(self::SORTABLE_COLUMNS)],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    public function actingUser(): User
    {
        /** @var User $user */
        $user = $this->user();

        return $user;
    }

    /**
     * @return array{search: string, status: string, sort: string, direction: string}
     */
    public function filters(): array
    {
        /** @var array<string, string|null> $validated */
        $validated = $this->validated();

        return [
            'search' => trim((string) ($validated['search'] ?? '')),
            'status' => (string) ($validated['status'] ?? ''),
            'sort' => (string) ($validated['sort'] ?? 'sort_order'),
            'direction' => (string) ($validated['direction'] ?? 'asc'),
        ];
    }
}
