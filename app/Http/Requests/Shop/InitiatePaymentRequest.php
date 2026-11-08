<?php

namespace App\Http\Requests\Shop;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Choosing how to pay for an order (P2-E1-T3).
 *
 * The shape only. Whether the method is active, whether it is offered at this
 * branch, to this city, for this total — all of that is
 * `PaymentMethodEligibility`'s, consulted inside `InitiatePayment`, because it
 * has to be re-checked against the real order anyway and a rule here would be
 * the second copy that goes stale.
 */
class InitiatePaymentRequest extends FormRequest
{
    /**
     * Authorisation is the route's `auth` middleware plus the controller's
     * check that the order belongs to the signed-in customer.
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
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['payment_method_id' => 'metode pembayaran'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_method_id.required' => 'Pilih metode pembayaran terlebih dahulu.',
            'payment_method_id.exists' => 'Metode pembayaran itu tidak tersedia.',
        ];
    }

    public function paymentMethodId(): int
    {
        return (int) $this->validated('payment_method_id');
    }
}
