<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ValidatesProductDetails;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProductRequest extends FormRequest
{
    use ValidatesProductDetails;

    /**
     * The route carries `can:products.update` and the controller asks the
     * policy, which additionally demands a central actor (BR-1).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The SKU and barcode belong to the product's default variant, which is
     * what the product screen edits (ADR-020), so their uniqueness checks skip
     * that variant rather than the product.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $defaultVariantId = $this->product()->variants()->where('is_default', true)->value('id');

        return [
            ...$this->productDetailRules(),
            'slug' => [
                'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('products', 'slug')->ignore($this->product()->getKey()),
            ],
            'sku' => [
                'required', 'string', 'max:60', 'regex:/^[A-Z0-9._-]+$/',
                Rule::unique('product_variants', 'sku')->ignore($defaultVariantId),
            ],
            'barcode' => [
                'nullable', 'string', 'max:60',
                Rule::unique('product_variants', 'barcode')->ignore($defaultVariantId),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => mb_strtolower(trim((string) $this->input('slug'))),
            'sku' => mb_strtoupper(trim((string) $this->input('sku'))),
        ]);
        $this->normalizeProductNumbers();
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->requireCompareAtPriceAboveBasePrice($validator)];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->productDetailAttributes();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->productDetailMessages(),
            'slug.regex' => 'Slug hanya boleh berisi huruf kecil, angka, dan tanda hubung (mis. kipas-angin-berdiri).',
        ];
    }

    private function product(): Product
    {
        /** @var Product $product */
        $product = $this->route('product');

        return $product;
    }
}
