<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What the panel sends when it decides a return's fate (P2-E4-T3).
 *
 * Used by the two buttons whose note is **optional** — approving a request and
 * closing one. Both are self-explanatory from the request they act on, so a
 * staff member is not made to type a sentence about them.
 *
 * Turning a request down has its own request (`RejectReturnRequest`) because
 * its reason is **required**: the sentence is shown to the customer, and one
 * told only "Ditolak" has nothing to act on.
 *
 * `OrderReturnPolicy` and the `*BlockedReason()` methods on `OrderReturn`
 * settle who may press these and whether the request can still be moved.
 */
class DecideReturnRequest extends FormRequest
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
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['notes' => 'catatan'];
    }

    /**
     * The note as typed, or null when it was left blank.
     */
    public function notes(): ?string
    {
        $notes = trim((string) $this->validated('notes'));

        return $notes === '' ? null : $notes;
    }
}
