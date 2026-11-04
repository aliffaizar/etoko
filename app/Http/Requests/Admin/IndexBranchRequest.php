<?php

namespace App\Http\Requests\Admin;

use App\Enums\BranchStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates and normalises the branch listing's query string.
 *
 * The sort column is whitelisted here rather than in the controller so an
 * arbitrary column can never reach `orderBy`.
 */
class IndexBranchRequest extends FormRequest
{
    /** Columns the listing may be ordered by. */
    public const SORTABLE_COLUMNS = ['code', 'name', 'city_name', 'sort_order', 'created_at'];

    /** Values of the "fulfilment" filter. */
    public const FULFILLMENT_VALUES = ['delivery', 'pickup'];

    /**
     * Authorisation is the route's `can:branches.view` middleware plus
     * `BranchPolicy::viewAny` in the controller.
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
            'status' => ['nullable', 'string', Rule::enum(BranchStatus::class)],
            'fulfillment' => ['nullable', 'string', Rule::in(self::FULFILLMENT_VALUES)],
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
     * The filters with their defaults applied, ready for the query and for the
     * React page to render its controls from.
     *
     * @return array{search: string, status: string, fulfillment: string, sort: string, direction: string}
     */
    public function filters(): array
    {
        /** @var array<string, string|null> $validated */
        $validated = $this->validated();

        return [
            'search' => trim((string) ($validated['search'] ?? '')),
            'status' => (string) ($validated['status'] ?? ''),
            'fulfillment' => (string) ($validated['fulfillment'] ?? ''),
            'sort' => (string) ($validated['sort'] ?? 'sort_order'),
            'direction' => (string) ($validated['direction'] ?? 'asc'),
        ];
    }
}
