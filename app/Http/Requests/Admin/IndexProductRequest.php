<?php

namespace App\Http\Requests\Admin;

use App\Enums\ProductPublicationState;
use App\Enums\ProductType;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates and normalises the product listing's query string.
 *
 * The sort column is whitelisted here rather than in the controller so an
 * arbitrary column can never reach `orderBy`.
 */
class IndexProductRequest extends FormRequest
{
    /** Columns the listing may be ordered by. */
    public const SORTABLE_COLUMNS = ['name', 'base_price', 'created_at', 'sold_count'];

    /** Values of the "featured" filter. */
    public const FEATURED_VALUES = ['yes', 'no'];

    /**
     * Authorisation is the route's `can:products.view` middleware plus
     * `ProductPolicy::viewAny` in the controller.
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
            'state' => ['nullable', 'string', Rule::enum(ProductPublicationState::class)],
            'type' => ['nullable', 'string', Rule::enum(ProductType::class)],
            'featured' => ['nullable', 'string', Rule::in(self::FEATURED_VALUES)],
            'sort' => ['nullable', 'string', Rule::in(self::SORTABLE_COLUMNS)],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function actingUser(): User
    {
        /** @var User $user */
        $user = $this->user();

        return $user;
    }

    /**
     * @return array{search: string, category: string, state: string, type: string, featured: string, sort: string, direction: string}
     */
    public function filters(): array
    {
        /** @var array<string, string|int|null> $validated */
        $validated = $this->validated();

        return [
            'search' => trim((string) ($validated['search'] ?? '')),
            'category' => (string) ($validated['category'] ?? ''),
            'state' => (string) ($validated['state'] ?? ''),
            'type' => (string) ($validated['type'] ?? ''),
            'featured' => (string) ($validated['featured'] ?? ''),
            'sort' => (string) ($validated['sort'] ?? 'created_at'),
            'direction' => (string) ($validated['direction'] ?? 'desc'),
        ];
    }
}
