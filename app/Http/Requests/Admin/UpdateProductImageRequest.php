<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A photo's alt text and the variant it shows (P1-E3-T7).
 *
 * The file itself is never replaced: a different photo is a new upload and a
 * delete, which keeps one row pointing at one file and makes the cleanup in
 * `DeleteProductImage` the only place a file is removed.
 */
class UpdateProductImageRequest extends FormRequest
{
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
        return [
            'alt_text' => ['nullable', 'string', 'max:255'],
            'product_variant_id' => [
                'nullable', 'integer',
                Rule::exists('product_variants', 'id')->where('product_id', $this->product()->getKey()),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $altText = trim((string) $this->input('alt_text'));
        $variantId = $this->input('product_variant_id');

        $this->merge([
            'alt_text' => $altText === '' ? null : $altText,
            'product_variant_id' => $variantId === '' ? null : $variantId,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'alt_text' => 'teks alternatif',
            'product_variant_id' => 'varian',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['product_variant_id.exists' => 'Varian tersebut bukan milik produk ini.'];
    }

    private function product(): Product
    {
        /** @var Product $product */
        $product = $this->route('product');

        return $product;
    }
}
