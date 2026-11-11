<?php

namespace App\Http\Requests\Admin;

use App\Enums\ReturnReason;
use App\Enums\ReturnStatus;
use App\Enums\ReturnType;
use App\Support\ReturnListQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The return queue's query string (P2-E4-T3).
 *
 * Listing state lives in the URL (ADR-013), so "retur barang rusak yang
 * menunggu persetujuan di cabang Bandung" can be bookmarked and sent to
 * whoever is on shift.
 */
class IndexReturnRequest extends FormRequest
{
    /**
     * Authorisation is the route's permission middleware plus
     * `OrderReturnPolicy`'s `viewAny` in the controller.
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
            'status' => ['nullable', 'string', Rule::enum(ReturnStatus::class)],
            'type' => ['nullable', 'string', Rule::enum(ReturnType::class)],
            'reason' => ['nullable', 'string', Rule::enum(ReturnReason::class)],
            'branch' => ['nullable', 'integer', 'exists:branches,id'],
            'open' => ['nullable', 'in:0,1'],
            'from' => ['nullable', 'date'],
            'until' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', 'string', Rule::in(array_keys(ReturnListQuery::SORTS))],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{search: string, status: string, type: string, reason: string, branch: string, open: string, from: string, until: string, sort: string, direction: string}
     */
    public function filters(): array
    {
        /** @var array<string, string|int|null> $validated */
        $validated = $this->validated();

        return [
            'search' => trim((string) ($validated['search'] ?? '')),
            'status' => (string) ($validated['status'] ?? ''),
            'type' => (string) ($validated['type'] ?? ''),
            'reason' => (string) ($validated['reason'] ?? ''),
            'branch' => (string) ($validated['branch'] ?? ''),
            'open' => (string) ($validated['open'] ?? ''),
            'from' => (string) ($validated['from'] ?? ''),
            'until' => (string) ($validated['until'] ?? ''),
            'sort' => (string) ($validated['sort'] ?? ReturnListQuery::DEFAULT_SORT),
            'direction' => (string) ($validated['direction'] ?? 'desc'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['from' => 'tanggal mulai', 'until' => 'tanggal akhir'];
    }
}
