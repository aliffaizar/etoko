<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ValidatesProductAttributeDetails;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProductAttributeValueRequest extends FormRequest
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
        return $this->attributeValueRules($this->attribute()->getKey(), $this->value()->getKey());
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

    private function value(): ProductAttributeValue
    {
        /** @var ProductAttributeValue $value */
        $value = $this->route('value');

        return $value;
    }
}
