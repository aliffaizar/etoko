<?php

namespace App\Http\Requests\Branch;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Turning a stock request down (P2-E3-T3).
 *
 * The reason is required: the requesting branch reads it, and "Ditolak" alone
 * leaves them asking again for the same thing.
 */
class RejectStockTransferRequest extends FormRequest
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
        return ['reason' => 'alasan penolakan'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Tulis alasan penolakan — alasan ini dibaca cabang peminta.',
            'reason.min' => 'Tulis alasan yang cukup jelas untuk dipahami cabang peminta.',
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
