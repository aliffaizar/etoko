<?php

namespace App\Concerns;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Shared validation for a category's own fields (P1-E3-T2).
 *
 * Create and edit submit the same fields, so the rules live here once. What
 * differs between them is the parent check: creating only has to look at how
 * deep the chosen parent already is, while editing also has to refuse a move
 * that would form a cycle or push the category's own subtree past the limit.
 *
 * @phpstan-require-extends FormRequest
 */
trait ValidatesCategoryDetails
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function categoryDetailRules(): array
    {
        return [
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'icon' => ['nullable', 'string', 'max:60'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function categoryDetailAttributes(): array
    {
        return [
            'parent_id' => 'kategori induk',
            'name' => 'nama kategori',
            'slug' => 'slug',
            'description' => 'deskripsi',
            'icon' => 'ikon',
            'is_active' => 'status aktif',
            'sort_order' => 'urutan tampil',
        ];
    }

    /**
     * The parent the request asks for, or null for a root category.
     */
    protected function selectedParent(): ?Category
    {
        $parentId = $this->input('parent_id');

        if ($parentId === null || $parentId === '') {
            return null;
        }

        return Category::query()->find((int) $parentId);
    }

    /**
     * Refuses a parent that is already as deep as the tree may go.
     *
     * `$ownHeight` is how many levels the category being saved brings with it:
     * 1 for a new category, and the height of its subtree when an existing one
     * is being moved.
     */
    protected function requireParentWithinDepthLimit(Validator $validator, int $ownHeight = 1): void
    {
        if ($validator->errors()->has('parent_id')) {
            return;
        }

        $parent = $this->selectedParent();

        if ($parent === null) {
            return;
        }

        if ($parent->depth() + $ownHeight <= Category::MAX_DEPTH) {
            return;
        }

        $validator->errors()->add('parent_id', $ownHeight > 1
            ? 'Kategori ini beserta subkategorinya tidak bisa dipindahkan ke induk tersebut, karena akan melewati batas '.Category::MAX_DEPTH.' tingkat.'
            : 'Kategori hanya boleh sampai '.Category::MAX_DEPTH.' tingkat, sehingga kategori tersebut tidak bisa dijadikan induk.');
    }

    /**
     * Refuses activating a category whose parent is inactive (ADR-019).
     *
     * The other half of the invariant — deactivating a parent hides everything
     * below it — is applied by the `UpdateCategory` action.
     */
    protected function requireActiveParentWhenActive(Validator $validator): void
    {
        if ($validator->errors()->has('parent_id') || ! $this->boolean('is_active')) {
            return;
        }

        $parent = $this->selectedParent();

        if ($parent === null || $parent->is_active) {
            return;
        }

        $validator->errors()->add(
            'is_active',
            "Kategori induk \"{$parent->name}\" sedang nonaktif, jadi kategori ini tidak bisa diaktifkan. Aktifkan induknya terlebih dahulu.",
        );
    }

    /**
     * `sort_order` is NOT NULL, but an emptied number input arrives as null
     * (Laravel converts empty strings), so it is read as "no preference" and
     * normalised to the column default rather than refused.
     */
    protected function normalizeCategorySortOrder(): void
    {
        if ($this->input('sort_order') === null || $this->input('sort_order') === '') {
            $this->merge(['sort_order' => 0]);
        }
    }
}
