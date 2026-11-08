<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What the panel sends when it decides a payment's fate (P2-E1-T4).
 *
 * Used by the two buttons whose note is **optional** — confirming the money
 * arrived, and clearing a stale attempt off the queue. Both are self-explanatory
 * from the row they act on, so a staff member is not made to type a sentence
 * about them.
 *
 * Rejecting a receipt has its own request (`RejectPaymentRequest`) because its
 * reason is **required**: the sentence is shown to the customer, and one told
 * only "Gagal" has nothing to act on.
 *
 * `PaymentPolicy` and `Payment::verificationBlockedReason()` settle who may
 * press these and whether the attempt can still be changed.
 */
class SettlePaymentRequest extends FormRequest
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
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['note' => 'catatan'];
    }

    /**
     * The note as typed, or null when it was left blank.
     */
    public function note(): ?string
    {
        $note = trim((string) $this->validated('note'));

        return $note === '' ? null : $note;
    }
}
