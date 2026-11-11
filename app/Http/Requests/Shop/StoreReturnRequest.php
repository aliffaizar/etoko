<?php

namespace App\Http\Requests\Shop;

use App\Enums\ReturnReason;
use App\Enums\ReturnType;
use App\Models\OrderReturn;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A customer's request to send goods back (P2-E4-T2).
 *
 * Validates the **shape**: a reason from the list, at least one line, a
 * positive quantity on each, and photos that are actually images. What it
 * deliberately does not validate is whether those units are still available to
 * return — that answer changes between the form being drawn and the button
 * being pressed, so it belongs to `RequestReturn` while it holds the order's
 * row lock, exactly as `StockLedger` owns "is there enough?" (BR-6).
 *
 * Photos are images only, no PDF: the point of them is for somebody at the
 * branch to *look* at the damage before the parcel arrives, and 3 MB each is
 * enough for a phone camera while staying small enough to upload on a mobile
 * connection. The limit on how many is `OrderReturn::MAX_PHOTOS`, so the form
 * and the model cannot disagree.
 *
 * "Alasan lain" makes the description **required**, because a label on its own
 * tells the branch nothing it can act on (`ReturnReason::requiresDescription()`).
 */
class StoreReturnRequest extends FormRequest
{
    /**
     * Authorisation is the `auth` middleware plus the `user_id` check in the
     * controller: another customer's order is not found rather than refused.
     */
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
            'type' => ['required', 'string', Rule::enum(ReturnType::class)],
            'reason' => ['required', 'string', Rule::enum(ReturnReason::class)],
            'description' => ['nullable', 'string', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:'.OrderReturn::MAX_PHOTOS],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer'],
            // Zero is allowed and then **dropped** (`toRequest()`): the form
            // lists every line of the order with a stepper on each, so the
            // untouched ones are not an error. A submission where every line
            // is zero is refused by `RequestReturn`, which is where "pick at
            // least one thing" belongs.
            'items.*.quantity' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * Require a description when the reason does not speak for itself.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $reason = ReturnReason::tryFrom((string) $this->string('reason'));

            if ($reason === null || ! $reason->requiresDescription()) {
                return;
            }

            if (trim((string) $this->string('description')) === '') {
                $validator->errors()->add(
                    'description',
                    'Jelaskan alasan retur Anda — cabang perlu tahu apa yang terjadi dengan barangnya.',
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
            'type' => 'jenis retur',
            'reason' => 'alasan retur',
            'description' => 'penjelasan',
            'photos' => 'foto barang',
            'items' => 'barang yang diretur',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Pilih minimal satu barang yang ingin diretur.',
            'items.min' => 'Pilih minimal satu barang yang ingin diretur.',
            'photos.max' => 'Maksimal '.OrderReturn::MAX_PHOTOS.' foto per pengajuan retur.',
            'photos.*.mimes' => 'Foto barang harus berupa gambar (JPG, PNG, atau WEBP).',
            'photos.*.max' => 'Ukuran setiap foto maksimal 3 MB.',
        ];
    }

    /**
     * The request as `RequestReturn` takes it, with the photo paths left to
     * the controller to fill in once the files are on disk.
     *
     * Lines with a quantity of zero are dropped rather than refused: a form
     * that lists every line of the order and lets the customer set a stepper
     * on the ones they want back is the right form, and the untouched lines
     * are not an error.
     *
     * @return array{type: ReturnType, reason: ReturnReason, description: string|null, photo_paths: array<int, string>, items: array<int, array{order_item_id: int, quantity: int}>}
     */
    public function toRequest(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        /** @var array<int, array{order_item_id: int|string, quantity: int|string}> $items */
        $items = $validated['items'];

        $lines = [];

        foreach ($items as $item) {
            $quantity = (int) $item['quantity'];

            if ($quantity > 0) {
                $lines[] = [
                    'order_item_id' => (int) $item['order_item_id'],
                    'quantity' => $quantity,
                ];
            }
        }

        $description = trim((string) ($validated['description'] ?? ''));

        return [
            'type' => ReturnType::from((string) $validated['type']),
            'reason' => ReturnReason::from((string) $validated['reason']),
            'description' => $description === '' ? null : $description,
            'photo_paths' => [],
            'items' => $lines,
        ];
    }
}
