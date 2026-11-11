<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Turning a return request down (P2-E4-T3).
 *
 * The reason is **required**, and that is the whole point of this being its
 * own request: it is shown to the customer on their order page. A rejection
 * that says only "Ditolak" leaves them resubmitting the same request.
 */
class RejectReturnRequest extends FormRequest
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
            'reason.required' => 'Tulis alasan penolakan — alasan ini dibaca pelanggan.',
            'reason.min' => 'Tulis alasan yang cukup jelas untuk dipahami pelanggan.',
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
