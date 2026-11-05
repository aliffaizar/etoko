<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ValidatesProductAttributeDetails;
use App\Models\ProductAttribute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreProductAttributeValueRequest extends FormRequest
{
    use ValidatesProductAttributeDetails;

    /**
     * The route carries `can:products.update` and the controller asks
     * `ProductAttributePolicy::update` on the owning attribute.
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
        return $this->attributeValueRules($this->attribute()->getKey());
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['color_hex' => $this->normalizedColorHex()]);
        $this->normalizeSortOrder();
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->requireColorHexOnlyForColorAttributes(
                $validator,
                $this->attribute()->type,
            ),
        ];
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

    /**
     * A colour picker sends `#C62828`; the column holds one spelling so two
     * values cannot describe the same colour differently.
     */
    private function normalizedColorHex(): ?string
    {
        $hex = trim((string) $this->input('color_hex'));

        return $hex === '' ? null : mb_strtolower($hex);
    }

    private function attribute(): ProductAttribute
    {
        /** @var ProductAttribute $attribute */
        $attribute = $this->route('attribute');

        return $attribute;
    }
}
