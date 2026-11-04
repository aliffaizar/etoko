<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ValidatesBranchDetails;
use App\Enums\BranchStatus;
use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBranchRequest extends FormRequest
{
    use ValidatesBranchDetails;

    /**
     * The route carries `can:branches.update` and the controller asks
     * `BranchPolicy::update`, which keeps a branch admin inside their own
     * branch.
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
        $branch = $this->branch();

        return [
            'code' => [
                'required', 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/',
                Rule::unique('branches', 'code')->ignore($branch->getKey()),
            ],
            // Editable because the slug is part of the branch's storefront URL
            // and a typo in the name would otherwise be permanent. Changing it
            // does change existing links.
            'slug' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('branches', 'slug')->ignore($branch->getKey()),
            ],
            'is_default' => ['boolean'],
            ...$this->branchDetailRules(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => mb_strtoupper(trim((string) $this->input('code'))),
            'slug' => mb_strtolower(trim((string) $this->input('slug'))),
        ]);
        $this->normalizeSortOrder();
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->requireAtLeastOneFulfillmentType($validator),
            fn (Validator $validator) => $this->protectTheStorefrontFallback($validator),
        ];
    }

    /**
     * The storefront needs exactly one usable fallback branch at all times, so
     * the flag can only move to another branch, never simply be dropped, and
     * the branch that holds it has to stay open for business.
     */
    private function protectTheStorefrontFallback(Validator $validator): void
    {
        $branch = $this->branch();
        $wantsDefault = $this->boolean('is_default');
        $status = $this->selectedStatus();

        if ($branch->is_default && ! $wantsDefault) {
            $validator->errors()->add(
                'is_default',
                'Tunjuk cabang lain sebagai cabang utama terlebih dahulu; storefront membutuhkan satu cabang utama.',
            );
        }

        if ($wantsDefault && $status === BranchStatus::Inactive) {
            $validator->errors()->add(
                'status',
                'Cabang utama tidak boleh dinonaktifkan. Tunjuk cabang lain sebagai cabang utama terlebih dahulu.',
            );
        }
    }

    private function branch(): Branch
    {
        /** @var Branch $branch */
        $branch = $this->route('branch');

        return $branch;
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
            'slug.alpha_dash' => 'Slug hanya boleh berisi huruf, angka, dan tanda hubung.',
        ];
    }
}
