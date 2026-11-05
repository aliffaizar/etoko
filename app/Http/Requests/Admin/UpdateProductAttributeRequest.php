<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ValidatesProductAttributeDetails;
use App\Models\ProductAttribute;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProductAttributeRequest extends FormRequest
{
    use ValidatesProductAttributeDetails;

    /**
     * The route carries `can:products.update` and the controller asks
     * `ProductAttributePolicy`, which additionally demands a central actor
     * (BR-1).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The type is deliberately absent: switching an attribute between
     * `select` and `color` would leave its existing values either without the
     * swatch the storefront expects or carrying one nothing draws. A new
     * attribute is the way to change it.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = $this->attributeDetailRules($this->attribute()->getKey());

        unset($rules['type']);

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeSortOrder();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->attributeDetailAttributes();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->attributeDetailMessages();
    }

    private function attribute(): ProductAttribute
    {
        /** @var ProductAttribute $attribute */
        $attribute = $this->route('attribute');

        return $attribute;
    }
}
