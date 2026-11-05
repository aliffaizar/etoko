<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ValidatesProductAttributeDetails;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductAttributeRequest extends FormRequest
{
    use ValidatesProductAttributeDetails;

    /**
     * The route carries `can:products.create` and the controller asks
     * `ProductAttributePolicy`, which additionally demands a central actor
     * (BR-1).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The slug is derived from the name by `ProductAttributeObserver`
     * (P1-E3-T8), so the form never asks for one.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->attributeDetailRules();
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
}
