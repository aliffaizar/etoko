<?php

namespace App\Http\Requests\Admin;

use App\Enums\RefundMethod;
use App\Enums\RefundStatus;
use App\Support\RefundListQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The refund listing's query string (P2-E4-T4).
 *
 * Listing state lives in the URL (ADR-013).
 */
class IndexRefundRequest extends FormRequest
{
    /**
     * Authorisation is the route's permission middleware plus `RefundPolicy`'s
     * `viewAny` in the controller.
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
            'status' => ['nullable', 'string', Rule::enum(RefundStatus::class)],
            'method' => ['nullable', 'string', Rule::enum(RefundMethod::class)],
            'branch' => ['nullable', 'integer', 'exists:branches,id'],
            'from' => ['nullable', 'date'],
            'until' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', 'string', Rule::in(array_keys(RefundListQuery::SORTS))],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{search: string, status: string, method: string, branch: string, from: string, until: string, sort: string, direction: string}
     */
    public function filters(): array
    {
        /** @var array<string, string|int|null> $validated */
        $validated = $this->validated();

        return [
            'search' => trim((string) ($validated['search'] ?? '')),
            'status' => (string) ($validated['status'] ?? ''),
            'method' => (string) ($validated['method'] ?? ''),
            'branch' => (string) ($validated['branch'] ?? ''),
            'from' => (string) ($validated['from'] ?? ''),
            'until' => (string) ($validated['until'] ?? ''),
            'sort' => (string) ($validated['sort'] ?? RefundListQuery::DEFAULT_SORT),
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
