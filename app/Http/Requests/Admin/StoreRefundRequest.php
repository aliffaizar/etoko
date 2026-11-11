<?php

namespace App\Http\Requests\Admin;

use App\Enums\RefundMethod;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Opening a refund against an order (P2-E4-T4).
 *
 * Validates the **shape**: a positive amount, a method the enum knows, and the
 * bank details a transfer cannot be made without. What it deliberately does
 * not validate is whether the order has that much left to give back — that
 * answer changes between the form being drawn and the button being pressed, so
 * it belongs to `ProcessRefund` while it holds the order's row lock, the same
 * division `StockLedger` has for "is there enough?" (BR-6).
 *
 * `reference` carries a meaning beyond being a note: passing it says **"I have
 * already sent this money"**, and `ProcessRefund` settles the refund at once
 * rather than leaving it in the queue. Leaving it blank opens the refund as
 * pending, which is what the queue is for.
 */
class StoreRefundRequest extends FormRequest
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
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'string', Rule::enum(RefundMethod::class)],
            'return_id' => ['nullable', 'integer'],
            'payment_id' => ['nullable', 'integer'],
            'account_info' => ['nullable', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Require the customer's bank details for a method that cannot be carried
     * out without them.
     *
     * Cash is handed over at a counter and a gateway refund goes back the way
     * it came, so neither has anywhere to send details to — only a transfer
     * does (`RefundMethod::needsAccountInfo()`).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $method = RefundMethod::tryFrom((string) $this->string('method'));

            if ($method === null || ! $method->needsAccountInfo()) {
                return;
            }

            if (trim((string) $this->string('account_info')) === '') {
                $validator->errors()->add(
                    'account_info',
                    'Tulis rekening tujuan pengembalian dana — bank, nomor, dan nama pemilik rekening.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'amount' => 'nominal pengembalian',
            'method' => 'cara pengembalian',
            'return_id' => 'retur terkait',
            'payment_id' => 'pembayaran asal',
            'account_info' => 'rekening tujuan',
            'reference' => 'nomor referensi',
            'notes' => 'catatan',
        ];
    }

    /**
     * The request as `ProcessRefund` takes it.
     *
     * @return array{amount: int, method: RefundMethod, return_id: int|null, payment_id: int|null, account_info: string|null, reference: string|null, notes: string|null}
     */
    public function toRequest(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return [
            'amount' => (int) $validated['amount'],
            'method' => RefundMethod::from((string) $validated['method']),
            'return_id' => isset($validated['return_id']) ? (int) $validated['return_id'] : null,
            'payment_id' => isset($validated['payment_id']) ? (int) $validated['payment_id'] : null,
            'account_info' => $this->trimmedOrNull('account_info'),
            'reference' => $this->trimmedOrNull('reference'),
            'notes' => $this->trimmedOrNull('notes'),
        ];
    }

    private function trimmedOrNull(string $key): ?string
    {
        $value = trim((string) $this->validated($key));

        return $value === '' ? null : $value;
    }
}
