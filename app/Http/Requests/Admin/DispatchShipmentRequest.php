<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What the dispatch form sends (P2-E2-T4).
 *
 * The shape only — whether the service is still active, whether the order is
 * ready to go, whether the courier will issue the waybill itself: all of that
 * is `DispatchShipment`'s, because it has to be re-checked inside the
 * transaction anyway and a rule here would be the second copy that goes stale.
 *
 * `tracking_number` is **nullable**, which looks wrong on a form whose whole
 * purpose is to record one. It is right: a courier served by an aggregator
 * issues the waybill when the booking is accepted, so the packer has nothing
 * to type. `DispatchShipment` refuses a dispatch that ends up with no number
 * from either source, which is the check that cannot be written here — it
 * depends on what the provider answered.
 *
 * The number is **not** normalised here either. `DispatchShipment` upper-cases
 * it and strips the spaces and dashes people copy off a receipt, so a waybill
 * recorded by the sweep and one typed at the counter end up spelled the same
 * way; doing it here as well would be a second rule to keep in step.
 */
class DispatchShipmentRequest extends FormRequest
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
            'courier_service_id' => ['required', 'integer', 'exists:courier_services,id'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function courierServiceId(): int
    {
        return (int) $this->validated('courier_service_id');
    }

    public function trackingNumber(): ?string
    {
        return $this->trimmed('tracking_number');
    }

    public function notes(): ?string
    {
        return $this->trimmed('notes');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'courier_service_id' => 'layanan pengiriman',
            'tracking_number' => 'nomor resi',
            'notes' => 'catatan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'courier_service_id.required' => 'Pilih kurir dan layanan pengirimannya.',
            'courier_service_id.exists' => 'Layanan pengiriman itu tidak tersedia.',
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
