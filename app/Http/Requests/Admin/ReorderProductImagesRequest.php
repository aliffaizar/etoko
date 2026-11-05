<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The new order of a product's gallery (P1-E3-T7).
 *
 * The whole sequence arrives at once, because that is what a drag produces:
 * moving one photo shifts every photo between its old and new place.
 */
class ReorderProductImagesRequest extends FormRequest
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
     * Every id has to belong to this product, so a reorder cannot reach into
     * another product's gallery.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'image_ids' => ['required', 'array', 'min:1'],
            'image_ids.*' => [
                'integer', 'distinct',
                Rule::exists('product_images', 'id')->where('product_id', $this->product()->getKey()),
            ],
        ];
    }

    /**
     * @return array<int, int>
     */
    public function imageIds(): array
    {
        /** @var array<int, int|string> $ids */
        $ids = $this->input('image_ids', []);

        return array_values(array_map('intval', $ids));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['image_ids' => 'urutan foto'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['image_ids.*.exists' => 'Ada foto yang bukan milik produk ini.'];
    }

    private function product(): Product
    {
        /** @var Product $product */
        $product = $this->route('product');

        return $product;
    }
}
