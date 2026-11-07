<?php

namespace App\Http\Requests\Shop;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A customer calling off their own order (P1-E7-T9).
 *
 * The reason is optional but asked for, because it is the only thing the shop
 * learns from a cancellation. Whether the order may still be called off is
 * `Order::cancellationBlockedReason()`'s answer, checked in the controller
 * against the order as it is now.
 */
class CancelOrderRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return $reason === null || trim((string) $reason) === '' ? null : trim((string) $reason);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reason' => 'alasan pembatalan'];
    }
}
