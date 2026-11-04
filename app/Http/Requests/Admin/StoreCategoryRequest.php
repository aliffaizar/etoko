<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ValidatesCategoryDetails;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCategoryRequest extends FormRequest
{
    use ValidatesCategoryDetails;

    /**
     * The route carries `can:categories.create` and the controller asks the
     * policy, which additionally demands a central actor (BR-1).
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
        return $this->categoryDetailRules();
    }

    /**
     * The slug is derived from the name by `CreateCategory` and becomes
     * editable on the category's own screen.
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeCategorySortOrder();
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->requireParentWithinDepthLimit($validator),
            fn (Validator $validator) => $this->requireActiveParentWhenActive($validator),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->categoryDetailAttributes();
    }
}
