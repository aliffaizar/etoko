<?php

namespace App\Http\Requests\Shop;

use App\Enums\BranchStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The branch a shopper picked by name (P1-E5-T2).
 *
 * Only a branch that accepts orders may be chosen, which is the same gate
 * `StorefrontBranch::sellingBranches()` applies to the picker's own list: a
 * closed branch has no shelf to sell from, so a crafted request naming one is
 * refused here rather than silently falling back.
 */
class ChooseBranchRequest extends FormRequest
{
    /**
     * Picking where to shop is open to guests; there is nothing to own yet.
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
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('branches', 'id')
                    ->where('status', BranchStatus::Active->value)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['branch_id' => 'cabang'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_id.exists' => 'Cabang itu sedang tidak melayani pembelian. Pilih cabang lain.',
        ];
    }

    public function branchId(): int
    {
        return (int) $this->validated('branch_id');
    }
}
