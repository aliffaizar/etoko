<?php

namespace App\Http\Requests\Admin;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The activate/deactivate toggle on the category listing.
 *
 * Activating is refused while the parent is inactive, which is the same rule
 * `UpdateCategoryRequest` applies to the full form (ADR-019).
 */
class UpdateCategoryStatusRequest extends FormRequest
{
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
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->boolean('is_active')) {
                return;
            }

            $parent = $this->category()->parent()->first();

            if ($parent === null || $parent->is_active) {
                return;
            }

            $validator->errors()->add(
                'is_active',
                "Kategori induk \"{$parent->name}\" sedang nonaktif, jadi kategori ini tidak bisa diaktifkan. Aktifkan induknya terlebih dahulu.",
            );
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['is_active' => 'status aktif'];
    }

    private function category(): Category
    {
        /** @var Category $category */
        $category = $this->route('category');

        return $category;
    }
}
