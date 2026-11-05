<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ValidatesProductVariantDetails;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreProductVariantRequest extends FormRequest
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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->variantDetailRules();
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
        return [fn (Validator $validator) => $this->validateVariantCombination($validator, $this->product())];
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
}
