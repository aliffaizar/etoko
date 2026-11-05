<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ValidatesProductDetails;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
{
    use ValidatesProductDetails;

    /**
     * The route carries `can:products.create` and the controller asks the
     * policy, which additionally demands a central actor (BR-1).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `sku` is required because `CreateProduct` opens the product's default
     * variant with it, and nothing downstream of a variant works without one
     * (ADR-020).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->productDetailRules(),
            'sku' => [
                'required', 'string', 'max:60', 'regex:/^[A-Z0-9._-]+$/',
                // Queries the table directly, so a soft-deleted variant keeps
                // its SKU: order history still points at it (ADR-010's reasoning).
                Rule::unique('product_variants', 'sku'),
            ],
            'barcode' => ['nullable', 'string', 'max:60', Rule::unique('product_variants', 'barcode')],
        ];
    }

    /**
     * The slug is derived from the name by `CreateProduct` and becomes
     * editable on the product's own screen.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['sku' => mb_strtoupper(trim((string) $this->input('sku')))]);
        $this->normalizeProductNumbers();
        $this->resolvePublishedAt();
    }

    /**
     * The product fields the Action wants: everything validated, minus the
     * `publication_mode` helper that only said how to read `published_at`.
     *
     * @return array<string, mixed>
     */
    public function productFields(): array
    {
        return $this->productAttributes();
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
        return $this->productDetailMessages();
    }
}
