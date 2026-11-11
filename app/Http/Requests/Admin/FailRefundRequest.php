<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Recording that a refund did not happen (P2-E4-T4).
 *
 * The reason is **required** because somebody will have to act on it, usually
 * by opening a second refund with the customer's corrected account details.
 * The amount goes straight back into what the order may still give back, so a
 * failure is never a dead end.
 */
class FailRefundRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reason' => 'alasan kegagalan'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Tulis alasan pengembalian dana gagal.',
            'reason.min' => 'Tulis alasan yang cukup jelas untuk ditindaklanjuti.',
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
