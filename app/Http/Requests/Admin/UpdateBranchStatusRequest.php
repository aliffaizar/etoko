<?php

namespace App\Http\Requests\Admin;

use App\Enums\BranchStatus;
use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The quick status change offered on the branch listing.
 *
 * It carries the same guard as the full edit form: the storefront's fallback
 * branch may not be switched off.
 */
class UpdateBranchStatusRequest extends FormRequest
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
        return [
            'status' => ['required', Rule::enum(BranchStatus::class)],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            /** @var Branch $branch */
            $branch = $this->route('branch');

            if ($branch->is_default && BranchStatus::tryFrom((string) $this->input('status')) === BranchStatus::Inactive) {
                $validator->errors()->add(
                    'status',
                    'Cabang utama tidak boleh dinonaktifkan. Tunjuk cabang lain sebagai cabang utama terlebih dahulu.',
                );
            }
        }];
    }

    public function branchStatus(): BranchStatus
    {
        return BranchStatus::from((string) $this->validated('status'));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['status' => 'status'];
    }
}
