<?php

namespace App\Http\Requests\Shop;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A voucher code typed on the cart screen (P2-E5-T3).
 *
 * Only the shape is checked here; whether the code exists and applies to the
 * basket is `VoucherValidator`'s answer, reported on the same field.
 */
class ApplyVoucherRequest extends FormRequest
{
    /**
     * The route is behind `auth`; a voucher belongs to a customer's basket.
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
            'voucher_code' => ['required', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['voucher_code' => 'kode voucher'];
    }
}
