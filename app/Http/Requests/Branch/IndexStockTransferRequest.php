<?php

namespace App\Http\Requests\Branch;

use App\Enums\StockTransferStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The stock transfer listing's query string, and the request form's (P2-E3-T3).
 *
 * `direction` is read from where the screen stands: "masuk" is what comes to
 * this branch, "keluar" what leaves it. A central actor picks the branch to
 * stand in with `?branch=`; with none, they see every transfer and direction
 * has nothing to be relative to.
 */
class IndexStockTransferRequest extends FormRequest
{
    public const DIRECTION_INCOMING = 'incoming';

    public const DIRECTION_OUTGOING = 'outgoing';

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
            'warehouse' => ['nullable', 'integer', 'exists:warehouses,id'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::enum(StockTransferStatus::class)],
            'direction' => ['nullable', 'string', Rule::in([self::DIRECTION_INCOMING, self::DIRECTION_OUTGOING])],
            'page' => ['nullable', 'integer', 'min:1'],
            'variant_search' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array{search: string, status: string, direction: string}
     */
    public function filters(): array
    {
        return [
            'search' => trim((string) ($this->validated('search') ?? '')),
            'status' => (string) ($this->validated('status') ?? ''),
            'direction' => (string) ($this->validated('direction') ?? ''),
        ];
    }

    public function branchId(): ?int
    {
        $value = $this->validated('branch');

        return $value === null ? null : (int) $value;
    }

    public function warehouseId(): ?int
    {
        $value = $this->validated('warehouse');

        return $value === null ? null : (int) $value;
    }

    public function variantSearch(): string
    {
        return trim((string) ($this->validated('variant_search') ?? ''));
    }
}
