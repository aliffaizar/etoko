<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The full featured list, in display order (P2-E8-T4).
 *
 * An empty list is allowed: it clears the front page's featured rail, which is
 * a decision the catalogue manager may make.
 */
class UpdateFeaturedProductsRequest extends FormRequest
{
    /**
     * The route carries `can:products.update` and the controller asks
     * `ProductPolicy::manageFeatured`.
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
            'product_ids' => ['present', 'array', 'max:200'],
            'product_ids.*' => ['integer', 'distinct', 'exists:products,id'],
        ];
    }

    /**
     * @return array<int, int>
     */
    public function productIds(): array
    {
        return array_values(array_map('intval', $this->validated('product_ids', [])));
    }
}
