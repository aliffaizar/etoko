<?php

namespace App\Http\Requests\Admin;

use App\Enums\FulfillmentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Support\OrderListQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The panel order listing's query string (P1-E7-T8).
 *
 * Listing state lives in the URL (ADR-013), so a filtered queue can be
 * bookmarked and sent to a colleague. `branch` is an ordinary filter here, not
 * a place the screen is looking at: a branch account is already narrowed to
 * its own orders by BR-3 and the filter adds nothing for them, while a central
 * account uses it to look at one branch's queue.
 */
class IndexOrderRequest extends FormRequest
{
    /**
     * Authorisation is the route's permission middleware plus `OrderPolicy`'s
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
            'status' => ['nullable', 'string', Rule::enum(OrderStatus::class)],
            'payment_status' => ['nullable', 'string', Rule::enum(PaymentStatus::class)],
            'fulfillment_type' => ['nullable', 'string', Rule::enum(FulfillmentType::class)],
            'branch' => ['nullable', 'integer', 'exists:branches,id'],
            'from' => ['nullable', 'date'],
            'until' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', 'string', Rule::in(array_keys(OrderListQuery::SORTS))],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{search: string, status: string, payment_status: string, fulfillment_type: string, branch: string, from: string, until: string, sort: string, direction: string}
     */
    public function filters(): array
    {
        /** @var array<string, string|int|null> $validated */
        $validated = $this->validated();

        return [
            'search' => trim((string) ($validated['search'] ?? '')),
            'status' => (string) ($validated['status'] ?? ''),
            'payment_status' => (string) ($validated['payment_status'] ?? ''),
            'fulfillment_type' => (string) ($validated['fulfillment_type'] ?? ''),
            'branch' => (string) ($validated['branch'] ?? ''),
            'from' => (string) ($validated['from'] ?? ''),
            'until' => (string) ($validated['until'] ?? ''),
            'sort' => (string) ($validated['sort'] ?? OrderListQuery::DEFAULT_SORT),
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
