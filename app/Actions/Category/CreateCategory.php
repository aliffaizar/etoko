<?php

namespace App\Actions\Category;

use App\Models\Category;

/**
 * Creates a category (P1-E3-T2).
 *
 * The create form does not ask for a slug; `CategoryObserver` derives one from
 * the name (P1-E3-T8), and the category's own screen makes it editable
 * afterwards, the same way a branch's slug works.
 */
final class CreateCategory
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes): Category
    {
        return Category::create($attributes);
    }
}
