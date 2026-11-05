<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ValidatesProductVariantDetails;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProductVariantRequest extends FormRequest
{
    use ValidatesProductVariantDetails;

    /**
     * The route carries `can:products.update` and the controller asks
     * `ProductPolicy::update`, which additionally demands a central actor
     * (BR-1).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The variant being edited is excluded from the uniqueness checks and from
     * the combination check, so saving it unchanged is not a conflict with
     * itself.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->variantDetailRules($this->variant()->getKey());
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeVariantFields();
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validateVariantCombination(
                $validator,
                $this->product(),
                $this->variant(),
            ),
            fn (Validator $validator) => $this->keepTheDefaultVariantSellable($validator),
        ];
    }

    /**
     * The default variant is what a listing prices and what a barcode scan
     * without a variant lands on, so it cannot be switched off while it holds
     * the flag. Hand the flag over first.
     */
    private function keepTheDefaultVariantSellable(Validator $validator): void
    {
        if (! $this->variant()->is_default) {
            return;
        }

        if ($this->boolean('is_active')) {
            return;
        }

        $validator->errors()->add(
            'is_active',
            'Varian utama tidak bisa dinonaktifkan. Tunjuk varian lain sebagai varian utama terlebih dahulu.',
        );
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->variantDetailAttributes();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->variantDetailMessages();
    }

    private function product(): Product
    {
        /** @var Product $product */
        $product = $this->route('product');

        return $product;
    }

    private function variant(): ProductVariant
    {
        /** @var ProductVariant $variant */
        $variant = $this->route('variant');

        return $variant;
    }
}
