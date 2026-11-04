<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Derives a URL slug that is not taken yet.
 *
 * Several tables carry a unique `slug` (branches, categories, products), and
 * each of them derives it from a name the user typed, so the suffixing rule
 * lives here once instead of in every Action.
 *
 * The query is handed in rather than a table name, so the caller decides what
 * counts as taken — `withTrashed()` for a table whose soft-deleted rows keep
 * their slug, the default scope for a table where they do not.
 *
 * The unique index on the column stays the real guard: two concurrent requests
 * can both read a slug as free, and the second insert is the one that fails.
 */
final class UniqueSlug
{
    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query  A fresh query over the table that owns the slug.
     * @param  int|string|null  $ignoreKey  Primary key to skip, so a row keeps its own slug when updated.
     */
    public static function from(string $value, Builder $query, string $column = 'slug', int|string|null $ignoreKey = null): string
    {
        $base = Str::slug($value);

        if ($base === '') {
            $base = 'item';
        }

        $slug = $base;
        $suffix = 1;

        while (self::isTaken($query, $column, $slug, $ignoreKey)) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private static function isTaken(Builder $query, string $column, string $slug, int|string|null $ignoreKey): bool
    {
        return (clone $query)
            ->where($column, $slug)
            ->when($ignoreKey !== null, fn (Builder $scoped) => $scoped->whereKeyNot($ignoreKey))
            ->exists();
    }
}
