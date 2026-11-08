<?php

namespace App\Http\Requests\Shop;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The receipt for a manual transfer (P2-E1-T3).
 *
 * Images and PDFs, because that is what a bank app produces — a screenshot or
 * an exported receipt. The type list is explicit rather than `mimes:image`:
 * a file is validated by what it *is*, and `image` alone would also accept an
 * SVG, which is a document that can carry a script.
 *
 * 4 MB is enough for a phone screenshot and small enough that a customer on a
 * mobile connection is not left watching a progress bar. The rule is here
 * rather than only in `php.ini` so the refusal is a sentence on the form
 * instead of a 413.
 *
 * Whether this attempt may still take a receipt at all —  open, a transfer,
 * not overdue — is `SubmitPaymentProof`'s, checked again under the row lock.
 */
class UploadPaymentProofRequest extends FormRequest
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
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:4096'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['proof' => 'bukti transfer', 'note' => 'catatan'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'proof.required' => 'Pilih berkas bukti transfer terlebih dahulu.',
            'proof.mimes' => 'Bukti transfer harus berupa gambar (JPG, PNG, WEBP) atau PDF.',
            'proof.max' => 'Ukuran bukti transfer maksimal 4 MB.',
        ];
    }

    public function note(): ?string
    {
        $note = trim((string) $this->validated('note'));

        return $note === '' ? null : $note;
    }
}
