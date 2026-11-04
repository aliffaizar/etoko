<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ValidatesCategoryDetails;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCategoryRequest extends FormRequest
{
    use ValidatesCategoryDetails;

    /**
     * The route carries `can:categories.update` and the controller asks the
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
        return [
            ...$this->categoryDetailRules(),
            'slug' => [
                'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                // Queries the table directly, so a soft-deleted category keeps
                // its slug and the links that were shared while it existed.
                Rule::unique('categories', 'slug')->ignore($this->category()->getKey()),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['slug' => mb_strtolower(trim((string) $this->input('slug')))]);
        $this->normalizeCategorySortOrder();
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->refuseParentInOwnSubtree($validator),
            fn (Validator $validator) => $this->requireParentWithinDepthLimit(
                $validator,
                $this->category()->subtreeHeight(),
            ),
            fn (Validator $validator) => $this->requireActiveParentWhenActive($validator),
        ];
    }

    /**
     * A category cannot be its own parent, nor a child of its own descendant:
     * either would cut that part of the tree off from the roots entirely.
     */
    private function refuseParentInOwnSubtree(Validator $validator): void
    {
        if ($validator->errors()->has('parent_id')) {
            return;
        }

        $parent = $this->selectedParent();

        if ($parent === null) {
            return;
        }

        $category = $this->category();

        if ($parent->is($category)) {
            $validator->errors()->add('parent_id', 'Kategori tidak bisa menjadi induk dari dirinya sendiri.');

            return;
        }

        if (in_array($parent->getKey(), $category->descendantIds(), strict: true)) {
            $validator->errors()->add('parent_id', "\"{$parent->name}\" adalah subkategori dari kategori ini, jadi tidak bisa dijadikan induknya.");
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->categoryDetailAttributes();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'Slug hanya boleh berisi huruf kecil, angka, dan tanda hubung (mis. alat-dapur).',
        ];
    }

    private function category(): Category
    {
        /** @var Category $category */
        $category = $this->route('category');

        return $category;
    }
}
