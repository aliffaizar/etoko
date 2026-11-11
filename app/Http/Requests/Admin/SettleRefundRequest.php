<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Recording that a refund's money has actually gone back (P2-E4-T4).
 *
 * The reference is **required**: this is the button a staff member presses
 * after making the transfer, and a refund the shop cannot evidence is a refund
 * it cannot prove it made when the customer calls back. For cash over a
 * counter it is the receipt number.
 */
class SettleRefundRequest extends FormRequest
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
            'reference' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reference' => 'nomor referensi', 'notes' => 'catatan'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reference.required' => 'Tulis nomor referensi transfer atau nomor bukti serah terima dana.',
        ];
    }

    public function reference(): string
    {
        return trim((string) $this->validated('reference'));
    }

    public function notes(): ?string
    {
        $notes = trim((string) $this->validated('notes'));

        return $notes === '' ? null : $notes;
    }
}
