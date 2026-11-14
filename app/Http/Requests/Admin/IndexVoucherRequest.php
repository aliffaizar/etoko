<?php

namespace App\Http\Requests\Admin;

use App\Enums\VoucherState;
use App\Enums\VoucherType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates and normalises the voucher listing's query string (P2-E5-T2).
 *
 * The sort column is whitelisted here so an arbitrary column never reaches
 * `orderBy`.
 */
class IndexVoucherRequest extends FormRequest
{
    /** Columns the listing may be ordered by. */
    public const SORTABLE_COLUMNS = ['code', 'name', 'used_count', 'ends_at', 'created_at'];

    /**
     * Authorisation is the route's `can:vouchers.manage` plus
     * `VoucherPolicy::viewAny` in the controller.
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
            'state' => ['nullable', Rule::enum(VoucherState::class)],
            'type' => ['nullable', Rule::enum(VoucherType::class)],
            'sort' => ['nullable', 'string', Rule::in(self::SORTABLE_COLUMNS)],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{search: string, state: string, type: string, sort: string, direction: string}
     */
    public function filters(): array
    {
        /** @var array<string, string|null> $validated */
        $validated = $this->validated();

        return [
            'search' => trim((string) ($validated['search'] ?? '')),
            'state' => (string) ($validated['state'] ?? ''),
            'type' => (string) ($validated['type'] ?? ''),
            'sort' => (string) ($validated['sort'] ?? 'created_at'),
            'direction' => (string) ($validated['direction'] ?? 'desc'),
        ];
    }
}
