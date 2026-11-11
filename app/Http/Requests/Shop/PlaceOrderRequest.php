<?php

namespace App\Http\Requests\Shop;

use App\Enums\FulfillmentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What the checkout form sends (P1-E7-T4, T5).
 *
 * The shape only — whether the branch takes pickups, whether the address is
 * this customer's, whether the stock is still there: all of that is
 * `PlaceOrder`'s, because it has to be re-checked inside the transaction
 * anyway and a rule here would be the second copy that goes stale.
 *
 * `address_id` is required for a delivery and refused for a pickup. Accepting
 * it for a pickup and ignoring it would let a customer believe they had chosen
 * where it was going.
 *
 * `courier_service_id` is required for a delivery and refused for a pickup,
 * for the same reason as the address: a shopper who was never asked which
 * courier to use has not finished checking out, and nothing is being sent
 * anywhere on a pickup. What it is **not** asked for is the cost —
 * `PlaceOrder` re-quotes the service against the real address and the real
 * weight, because a delivery charge the browser sends is a delivery charge the
 * browser chose (`P2-E2-T3`).
 *
 * `payment_method_id` is required here but **optional** on `PlaceOrder`
 * (P2-E1-T3). The storefront insists on it because a checkout that leaves the
 * customer wondering how to pay is not a checkout; the action does not,
 * because a POS sale and an admin-entered order are paid in their own time and
 * their payment is a separate record either way.
 */
class PlaceOrderRequest extends FormRequest
{
    /**
     * Authorisation is the route's `auth` middleware: an order belongs to an
     * account, and guest checkout is not offered yet.
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
            'fulfillment_type' => ['required', Rule::enum(FulfillmentType::class)],
            'address_id' => [
                Rule::requiredIf($this->input('fulfillment_type') === FulfillmentType::Delivery->value),
                Rule::prohibitedIf($this->input('fulfillment_type') === FulfillmentType::Pickup->value),
                'nullable',
                'integer',
                'exists:addresses,id',
            ],
            'courier_service_id' => [
                Rule::requiredIf($this->input('fulfillment_type') === FulfillmentType::Delivery->value),
                Rule::prohibitedIf($this->input('fulfillment_type') === FulfillmentType::Pickup->value),
                'nullable',
                'integer',
                'exists:courier_services,id',
            ],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'customer_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'fulfillment_type' => 'cara pemenuhan',
            'address_id' => 'alamat pengiriman',
            'courier_service_id' => 'layanan pengiriman',
            'payment_method_id' => 'metode pembayaran',
            'customer_note' => 'catatan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'address_id.required' => 'Pilih alamat pengiriman terlebih dahulu.',
            'address_id.prohibited' => 'Pesanan ambil sendiri tidak memerlukan alamat pengiriman.',
            'courier_service_id.required' => 'Pilih layanan pengiriman terlebih dahulu.',
            'courier_service_id.prohibited' => 'Pesanan ambil sendiri tidak memerlukan layanan pengiriman.',
            'courier_service_id.exists' => 'Layanan pengiriman itu tidak tersedia.',
            'payment_method_id.required' => 'Pilih metode pembayaran terlebih dahulu.',
            'payment_method_id.exists' => 'Metode pembayaran itu tidak tersedia.',
        ];
    }

    public function paymentMethodId(): int
    {
        return (int) $this->validated('payment_method_id');
    }

    /**
     * @return array{fulfillment_type: FulfillmentType, address_id: int|null, courier_service_id: int|null, customer_note: string|null}
     */
    public function toCheckoutData(): array
    {
        $addressId = $this->validated('address_id');
        $courierServiceId = $this->validated('courier_service_id');

        return [
            'fulfillment_type' => FulfillmentType::from((string) $this->validated('fulfillment_type')),
            'address_id' => $addressId === null ? null : (int) $addressId,
            'courier_service_id' => $courierServiceId === null ? null : (int) $courierServiceId,
            'customer_note' => $this->validated('customer_note'),
        ];
    }
}
