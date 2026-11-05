<?php

namespace App\Http\Requests\Branch;

use App\Enums\StockDocumentStatus;
use App\Enums\StockIssueReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The goods-in and goods-out listings' query string (P1-E4-T5, T6).
 *
 * Shared by both, because both listings answer the same questions: which of my
 * branch's documents, in what state, over what period. The reason filter is
 * only meaningful for goods-out and is simply absent from the other screen's
 * filter bar; validating it here costs the goods-in screen nothing and keeps
 * the two listings reading one set of rules.
 *
 * Stock counts have a state these documents do not ("sedang dihitung"), so
 * they get their own `IndexStockOpnameRequest` rather than a status rule
 * widened to accept values half the screens cannot be in.
 */
class IndexStockDocumentRequest extends FormRequest
{
    /**
     * Authorisation is the route's permission middleware plus the document
     * policy's `viewAny` in the controller.
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
            'warehouse' => ['nullable', 'integer', 'exists:warehouses,id'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::enum(StockDocumentStatus::class)],
            'reason' => ['nullable', 'string', Rule::enum(StockIssueReason::class)],
            'from' => ['nullable', 'date'],
            'until' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            // What has been typed into the line editor's variant picker; the
            // form reloads only that prop as it changes.
            'variant_search' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array{search: string, status: string, reason: string, from: string, until: string}
     */
    public function filters(): array
    {
        /** @var array<string, string|int|null> $validated */
        $validated = $this->validated();

        return [
            'search' => trim((string) ($validated['search'] ?? '')),
            'status' => (string) ($validated['status'] ?? ''),
            'reason' => (string) ($validated['reason'] ?? ''),
            'from' => (string) ($validated['from'] ?? ''),
            'until' => (string) ($validated['until'] ?? ''),
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

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['from' => 'tanggal mulai', 'until' => 'tanggal akhir'];
    }
}
