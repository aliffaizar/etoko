<?php

namespace App\Http\Requests\Admin;

use App\Enums\PaymentMethodType;
use App\Enums\PaymentTransactionStatus;
use App\Support\PaymentListQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The payment queue's query string (P2-E1-T4).
 *
 * Listing state lives in the URL (ADR-013), so "transfer menunggu konfirmasi
 * di cabang Bandung" can be bookmarked and sent to whoever is on shift.
 */
class IndexPaymentRequest extends FormRequest
{
    /**
     * Authorisation is the route's permission middleware plus `PaymentPolicy`'s
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
            'status' => ['nullable', 'string', Rule::enum(PaymentTransactionStatus::class)],
            'method_type' => ['nullable', 'string', Rule::enum(PaymentMethodType::class)],
            'branch' => ['nullable', 'integer', 'exists:branches,id'],
            'awaiting_proof' => ['nullable', 'in:0,1'],
            'from' => ['nullable', 'date'],
            'until' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', 'string', Rule::in(array_keys(PaymentListQuery::SORTS))],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{search: string, status: string, method_type: string, branch: string, awaiting_proof: string, from: string, until: string, sort: string, direction: string}
     */
    public function filters(): array
    {
        /** @var array<string, string|int|null> $validated */
        $validated = $this->validated();

        return [
            'search' => trim((string) ($validated['search'] ?? '')),
            'status' => (string) ($validated['status'] ?? ''),
            'method_type' => (string) ($validated['method_type'] ?? ''),
            'branch' => (string) ($validated['branch'] ?? ''),
            'awaiting_proof' => (string) ($validated['awaiting_proof'] ?? ''),
            'from' => (string) ($validated['from'] ?? ''),
            'until' => (string) ($validated['until'] ?? ''),
            'sort' => (string) ($validated['sort'] ?? PaymentListQuery::DEFAULT_SORT),
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
