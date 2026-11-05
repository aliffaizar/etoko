<?php

namespace App\Observers\Concerns;

use App\Support\UniqueSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Derives a missing slug on the way into the database (P1-E3-T8).
 *
 * The Actions behind the panel forms used to each call `UniqueSlug` themselves,
 * which left every other way in — a seeder, a CSV import (P1-E3-T11), tinker —
 * free to write a row with an empty slug. Doing it in an observer makes the
 * rule hold for all of them: a blank slug is filled from the record's name,
 * and a slug that is already taken gets a numeric suffix.
 *
 * A slug the caller supplied is left alone when it is free, so an existing
 * record keeps the URL it was shared under. The unique index stays the real
 * guard against a race between two requests.
 */
trait FillsUniqueSlug
{
    /**
     * The query that decides what counts as taken.
     *
     * Soft-deleted rows are included where the table keeps their slug, so a
     * restored record still owns its old links.
     *
     * @return Builder<covariant Model>
     */
    abstract protected function slugLookupQuery(): Builder;

    /**
     * Fills the slug before an insert.
     */
    protected function fillSlugOnCreate(Model $model): void
    {
        $model->setAttribute('slug', $this->resolveSlug($model, null));
    }

    /**
     * Fills the slug before an update, letting the record keep its own.
     *
     * An untouched, non-empty slug costs no query: every save of a product or
     * category would otherwise pay for a lookup it cannot learn anything from.
     */
    protected function fillSlugOnUpdate(Model $model): void
    {
        $slug = trim((string) $model->getAttribute('slug'));

        if ($slug !== '' && ! $model->isDirty('slug')) {
            return;
        }

        $model->setAttribute('slug', $this->resolveSlug($model, $model->getKey()));
    }

    private function resolveSlug(Model $model, int|string|null $ignoreKey): string
    {
        $slug = trim((string) $model->getAttribute('slug'));
        $source = $slug === '' ? (string) $model->getAttribute('name') : $slug;

        return UniqueSlug::from($source, $this->slugLookupQuery(), 'slug', $ignoreKey);
    }
}
