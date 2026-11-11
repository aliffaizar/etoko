<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Handing a Click & Collect order over at the counter (P2-E2-T7).
 *
 * `pickup_code` is required **unless** the handover is being waived, and the
 * code itself is never compared here: `ConfirmOrderPickup` does that inside
 * the row lock with `hash_equals`, because the comparison is the decision
 * rather than the shape of the input, and two tills must not both accept the
 * same code for the same order.
 *
 * `override` is how a counter gets past a customer who has lost their phone —
 * a real problem, and a shop with no way around it is a shop that writes the
 * codes down. It is explicit, recorded in the order's timeline with the staff
 * member's name, and never the default.
 */
class ConfirmOrderPickupRequest extends FormRequest
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
            'pickup_code' => [
                Rule::requiredIf(fn (): bool => ! $this->boolean('override_missing_code')),
                'nullable',
                'string',
                'max:20',
            ],
            'override_missing_code' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function pickupCode(): ?string
    {
        $code = $this->validated('pickup_code');

        return $code === null || trim((string) $code) === '' ? null : trim((string) $code);
    }

    /**
     * Whether the staff member is accepting the handover without the code.
     */
    public function overridesMissingCode(): bool
    {
        return $this->boolean('override_missing_code');
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return $note === null || trim((string) $note) === '' ? null : trim((string) $note);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'pickup_code' => 'kode pengambilan',
            'override_missing_code' => 'lewati kode pengambilan',
            'note' => 'catatan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pickup_code.required' => 'Masukkan kode pengambilan, atau tandai bahwa kodenya dilewati.',
        ];
    }
}
