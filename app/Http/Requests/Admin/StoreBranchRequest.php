<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ValidatesBranchDetails;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBranchRequest extends FormRequest
{
    use ValidatesBranchDetails;

    /**
     * The route carries `can:branches.create` and the controller asks the
     * policy, which additionally demands a central actor.
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
            'code' => [
                'required', 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/',
                // `unique` queries the table directly, so a soft-deleted branch
                // keeps its code until that row is purged (same reasoning as
                // ADR-010 for user email addresses).
                Rule::unique('branches', 'code'),
            ],
            ...$this->branchDetailRules(),
        ];
    }

    /**
     * The slug is derived from the name by `CreateBranch`, and `is_default` is
     * decided there too: the very first branch becomes the storefront fallback.
     * Both are editable afterwards on the branch's own screen.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['code' => mb_strtoupper(trim((string) $this->input('code')))]);
        $this->normalizeSortOrder();
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->requireAtLeastOneFulfillmentType($validator)];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->branchDetailAttributes();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Kode cabang hanya boleh berisi huruf kapital, angka, dan tanda hubung (mis. JKT-01).',
        ];
    }
}
