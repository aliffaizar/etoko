<?php

namespace App\Http\Requests\Branch;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters of the packing list (P3-E6-T4).
 *
 * Two tabs rather than a free status filter: the list only ever holds orders
 * that are waiting to be packed (`paid`) or being packed (`processing`), the
 * two statuses in which the goods are the warehouse's business.
 */
class IndexPackingRequest extends FormRequest
{
    public const STAGE_VALUES = ['to_pack', 'packing'];

    /**
     * The route carries `can:orders.pack`; the listing is narrowed to the
     * branch `BranchWorkspace` resolves.
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
            'branch' => ['nullable', 'integer', 'exists:branches,id'],
            'stage' => ['nullable', 'string', Rule::in(self::STAGE_VALUES)],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{stage: string, search: string}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'stage' => (string) ($validated['stage'] ?? 'to_pack'),
            'search' => trim((string) ($validated['search'] ?? '')),
        ];
    }

    public function branchId(): ?int
    {
        $value = $this->validated('branch');

        return $value === null ? null : (int) $value;
    }
}
