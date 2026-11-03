<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class UpdateRolePermissionsRequest extends FormRequest
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
        /** @var array<string, array<int, string>> $groups */
        $groups = config('rbac.permissions');

        return [
            'permissions' => ['nullable', 'array'],
            // Only names from the catalogue are accepted, so a stale form
            // cannot resurrect a retired permission (ADR-009).
            'permissions.*' => ['string', Rule::in(Arr::flatten($groups))],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function permissions(): array
    {
        /** @var array<int, string> $permissions */
        $permissions = array_values(array_unique($this->validated('permissions', [])));

        return $permissions;
    }
}
