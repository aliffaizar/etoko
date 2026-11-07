<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Handing an order to another branch (P1-E7-T10).
 *
 * Whether the destination trades, and whether it holds enough stock, is
 * `AssignOrderBranch`'s answer — both have to be true at the moment the holds
 * move, which is inside its transaction.
 */
class AssignOrderBranchRequest extends FormRequest
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
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['branch_id' => 'cabang tujuan', 'note' => 'catatan'];
    }
}
