<?php

namespace App\Actions\Category;

use App\Models\Category;
use App\Support\UniqueSlug;

/**
 * Creates a category (P1-E3-T2).
 *
 * The slug is derived from the name, because the create form does not ask for
 * one; it becomes editable on the category's own screen afterwards, the same
 * way a branch's slug does.
 *
 * Soft-deleted categories keep their slug, so a restored category still owns
 * the links that were shared while it existed.
 */
final class CreateCategory
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes): Category
    {
        return Category::create([
            ...$attributes,
            'slug' => $attributes['slug'] ?? UniqueSlug::from((string) $attributes['name'], Category::withTrashed()),
        ]);
    }
}
