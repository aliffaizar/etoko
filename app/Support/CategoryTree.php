<?php

namespace App\Support;

use App\Models\Category;
use Illuminate\Support\Collection;

/**
 * Arranges a flat set of categories into the order the panel draws them in.
 *
 * Every screen that shows categories needs the same thing: parents before
 * their children, each level in its own `sort_order`, and each row's depth so
 * it can be indented. Doing that in memory keeps it to the one query that
 * loaded the set, instead of one query per level.
 *
 * A category whose parent is missing from the set — filtered out, or soft
 * deleted — is treated as a root, so it stays reachable in the panel rather
 * than disappearing from a tree it can no longer be hung on.
 */
final class CategoryTree
{
    /**
     * @param  Collection<int, Category>  $categories
     */
    private function __construct(private readonly Collection $categories) {}

    /**
     * @param  Collection<int, Category>  $categories
     */
    public static function of(Collection $categories): self
    {
        return new self($categories);
    }

    /**
     * The categories in display order, each with its depth and ancestor names.
     *
     * Depth counts from 1, matching `Category::depth()`.
     *
     * @return array<int, array{category: Category, depth: int, path: array<int, string>}>
     */
    public function flatten(): array
    {
        $present = $this->categories->keyBy(fn (Category $category): int => $category->getKey());

        /** @var Collection<int|string, Collection<int, Category>> $childrenOf */
        $childrenOf = $this->categories->groupBy(
            fn (Category $category): int|string => $category->parent_id !== null && $present->has($category->parent_id)
                ? $category->parent_id
                : 'root',
        );

        return $this->walk($childrenOf, 'root', 1, []);
    }

    /**
     * @param  Collection<int|string, Collection<int, Category>>  $childrenOf
     * @param  array<int, string>  $path
     * @return array<int, array{category: Category, depth: int, path: array<int, string>}>
     */
    private function walk(Collection $childrenOf, int|string $parentKey, int $depth, array $path): array
    {
        $rows = [];

        /** @var Collection<int, Category> $children */
        $children = $childrenOf->get($parentKey, new Collection);

        foreach ($this->inDisplayOrder($children) as $category) {
            $rows[] = ['category' => $category, 'depth' => $depth, 'path' => $path];

            $rows = [
                ...$rows,
                ...$this->walk($childrenOf, $category->getKey(), $depth + 1, [...$path, $category->name]),
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return array<int, Category>
     */
    private function inDisplayOrder(Collection $categories): array
    {
        return $categories
            ->sortBy([
                fn (Category $a, Category $b): int => $a->sort_order <=> $b->sort_order,
                fn (Category $a, Category $b): int => strcasecmp($a->name, $b->name),
            ])
            ->values()
            ->all();
    }
}
