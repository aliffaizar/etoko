<?php

namespace App\Http\Requests\Admin;

use App\Enums\ShipmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moving a parcel along by hand (P2-E2-T4).
 *
 * The whole timeline of a manual courier is what the branch types here, which
 * is why `description` exists at all: "Dalam Perjalanan" with nothing beside it
 * tells a waiting customer nothing, and the status label alone is what
 * `RecordShipmentTracking` falls back to when the field is left empty.
 *
 * Only the statuses a branch may *set* are accepted — `ShipmentStatus::Pending`
 * is refused, because a shipment starts there and moving a parcel back to "not
 * yet collected" is not something a courier does. Whether this particular
 * parcel may reach the status asked for is `RecordShipmentTracking`'s, under
 * the row lock: a parcel still sitting at the branch has nothing to report,
 * and a courier's scan that already moved it further is not undone by a form.
 *
 * `location` is the courier's branch or depot as the staff member knows it,
 * and it is optional because most of the time they only know the status.
 */
class UpdateShipmentStatusRequest extends FormRequest
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
            'status' => [
                'required',
                'string',
                Rule::enum(ShipmentStatus::class)->only(ShipmentStatus::manualTransitions()),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function status(): ShipmentStatus
    {
        return ShipmentStatus::from((string) $this->validated('status'));
    }

    public function description(): ?string
    {
        return $this->trimmed('description');
    }

    public function location(): ?string
    {
        return $this->trimmed('location');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'status' => 'status pengiriman',
            'description' => 'keterangan',
            'location' => 'lokasi',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Pilih status pengiriman yang baru.',
        ];
    }

    /**
     * A field with its surrounding spaces gone, or null when nothing was typed.
     */
    private function trimmed(string $key): ?string
    {
        $value = $this->validated($key);

        return $value === null || trim((string) $value) === '' ? null : trim((string) $value);
    }
}
