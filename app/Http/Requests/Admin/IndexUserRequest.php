<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates and normalises the staff listing's query string.
 *
 * The sort column is whitelisted here rather than in the controller so an
 * arbitrary column can never reach `orderBy`.
 */
class IndexUserRequest extends FormRequest
{
    /** Columns the listing may be ordered by. */
    public const SORTABLE_COLUMNS = ['name', 'email', 'created_at', 'last_login_at'];

    /** Sentinel for the branch filter that means "central staff, no branch". */
    public const CENTRAL_BRANCH = 'central';

    /**
     * Authorisation is the route's `can:users.view` middleware plus
     * `UserPolicy::viewAny` in the controller.
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
            'role' => ['nullable', 'string', Rule::in(UserRole::staffRoleValues())],
            'branch' => ['nullable', 'string'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'sort' => ['nullable', 'string', Rule::in(self::SORTABLE_COLUMNS)],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * The signed-in user, typed for the query that is built from these filters.
     */
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
     * @return array{search: string, role: string, branch: string, status: string, sort: string, direction: string}
     */
    public function filters(): array
    {
        /** @var array<string, string|null> $validated */
        $validated = $this->validated();

        return [
            'search' => trim((string) ($validated['search'] ?? '')),
            'role' => (string) ($validated['role'] ?? ''),
            'branch' => (string) ($validated['branch'] ?? ''),
            'status' => (string) ($validated['status'] ?? ''),
            'sort' => (string) ($validated['sort'] ?? 'name'),
            'direction' => (string) ($validated['direction'] ?? 'asc'),
        ];
    }
}
