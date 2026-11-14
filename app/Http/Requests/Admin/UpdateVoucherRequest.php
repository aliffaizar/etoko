<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ValidatesVoucherDetails;
use App\Models\Voucher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateVoucherRequest extends FormRequest
{
    use ValidatesVoucherDetails;

    /**
     * The route carries `can:vouchers.manage` and the controller asks
     * `VoucherPolicy`, which also demands a central actor.
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
        return $this->voucherDetailRules($this->voucher());
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeVoucherInput();
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateVoucherCombination($validator, $this->voucher())];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->voucherDetailAttributes();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->voucherDetailMessages();
    }

    private function voucher(): Voucher
    {
        /** @var Voucher $voucher */
        $voucher = $this->route('voucher');

        return $voucher;
    }
}
