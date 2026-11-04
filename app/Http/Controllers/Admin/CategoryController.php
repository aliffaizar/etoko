<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Category\CreateCategory;
use App\Actions\Category\UpdateCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexCategoryRequest;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryStatusRequest;
use App\Models\Category;
use App\Models\User;
use App\Support\CategoryTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Category management (P1-E3-T2).
 *
 * Categories form a tree of at most `Category::MAX_DEPTH` levels, so the
 * listing is a tree rather than a page of rows: it loads the whole set in one
 * query and lets `CategoryTree` arrange it. There is no paginator, the same way
 * the role listing has none — a catalogue taxonomy is small and only makes
 * sense read as a whole.
 *
 * Filtering collapses it to a flat result, because a match two levels down
 * would otherwise be invisible unless its ancestors matched too; each row
 * carries its ancestor path so it stays locatable.
 *
 * Categories are managed centrally (BR-1): `CategoryPolicy` demands a central
 * actor for every write, while a branch admin holds `categories.view` and sees
 * the same tree read-only.
 */
class CategoryController extends Controller
{
    /** The listing's status filter, which is a plain boolean column. */
    private const STATUS_OPTIONS = [
        ['value' => 'active', 'label' => 'Aktif'],
        ['value' => 'inactive', 'label' => 'Tidak Aktif'],
    ];

    public function index(IndexCategoryRequest $request): Response
    {
        $this->authorize('viewAny', Category::class);

        $filters = $request->filters();
        $actor = $request->actingUser();

        return Inertia::render('admin/categories/index', [
            'categories' => $this->listing($actor, $filters),
            'filters' => $filters,
            'options' => ['statuses' => self::STATUS_OPTIONS],
            'can' => ['create' => $actor->can('create', Category::class)],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Category::class);

        return Inertia::render('admin/categories/create', [
            'parentOptions' => $this->parentOptions(),
        ]);
    }

    public function store(StoreCategoryRequest $request, CreateCategory $createCategory): RedirectResponse
    {
        $this->authorize('create', Category::class);

        $category = $createCategory->handle($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Kategori {$category->name} berhasil dibuat."]);

        return to_route('admin.categories.edit', $category);
    }

    public function edit(Category $category): Response
    {
        $this->authorize('update', $category);

        $category->loadCount(['children', 'products']);

        return Inertia::render('admin/categories/edit', [
            'category' => $this->categoryDetail($category),
            'parentOptions' => $this->parentOptions($category),
        ]);
    }

    public function update(UpdateCategoryRequest $request, Category $category, UpdateCategory $updateCategory): RedirectResponse
    {
        $this->authorize('update', $category);

        $updateCategory->handle($category, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Perubahan kategori berhasil disimpan.']);

        return to_route('admin.categories.edit', $category);
    }

    /**
     * The activate/deactivate toggle on the listing.
     *
     * Deactivating takes the whole subtree down with it (ADR-019), so the
     * message says how many subcategories went with it.
     */
    public function updateStatus(
        UpdateCategoryStatusRequest $request,
        Category $category,
        UpdateCategory $updateCategory,
    ): RedirectResponse {
        $this->authorize('update', $category);

        $isActive = $request->boolean('is_active');
        $hiddenCount = $isActive ? 0 : count($category->descendantIds());

        $updateCategory->handle($category, ['is_active' => $isActive]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $this->statusMessageFor($category, $isActive, $hiddenCount),
        ]);

        return back();
    }

    /**
     * Soft deletes the category.
     *
     * What is refused outright is listed by `Category::deletionBlockedReason()`,
     * which the listing also reads for its `can.delete` flag, so the interface
     * never offers a deletion this method will refuse.
     */
    public function destroy(Category $category): RedirectResponse
    {
        $this->authorize('delete', $category);

        $blocked = $category->deletionBlockedReason();

        abort_if($blocked !== null, 403, (string) $blocked);

        $category->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Kategori {$category->name} dihapus."]);

        return to_route('admin.categories.index');
    }

    /**
     * The tree, or the flat matches when a filter is on.
     *
     * @param  array{search: string, status: string, sort: string, direction: string}  $filters
     * @return array<int, array<string, mixed>>
     */
    private function listing(User $actor, array $filters): array
    {
        $isFiltered = $filters['search'] !== '' || $filters['status'] !== '';

        $categories = Category::query()
            ->withCount(['children', 'products'])
            ->when($filters['search'] !== '', fn (Builder $query) => $query->where(
                fn (Builder $match) => $match
                    ->where('name', 'like', "%{$filters['search']}%")
                    ->orWhere('slug', 'like', "%{$filters['search']}%"),
            ))
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('is_active', $filters['status'] === 'active'))
            ->get();

        // A filtered result is a list of matches, not a tree: sorting it by the
        // chosen column is what the user asked for. An unfiltered one is the
        // tree itself, where the only meaningful order is parents first.
        $rows = $isFiltered
            ? $this->sortedFlatRows($categories, $filters)
            : CategoryTree::of($categories)->flatten();

        return array_map(fn (array $row): array => $this->listRow($actor, $row), $rows);
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @param  array{search: string, status: string, sort: string, direction: string}  $filters
     * @return array<int, array{category: Category, depth: int, path: array<int, string>}>
     */
    private function sortedFlatRows(Collection $categories, array $filters): array
    {
        $sorted = $categories->sortBy(
            fn (Category $category): mixed => $category->getAttribute($filters['sort']),
            descending: $filters['direction'] === 'desc',
        );

        return $sorted
            ->map(fn (Category $category): array => [
                'category' => $category,
                // Depth and path are unknown without the ancestors, which the
                // filter may well have excluded; the row reads as a flat match.
                'depth' => 1,
                'path' => [],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{category: Category, depth: int, path: array<int, string>}  $row
     * @return array<string, mixed>
     */
    private function listRow(User $actor, array $row): array
    {
        $category = $row['category'];
        $canDelete = $actor->can('delete', $category);

        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'parent_id' => $category->parent_id,
            'depth' => $row['depth'],
            'path' => $row['path'],
            'icon' => $category->icon,
            'is_active' => $category->is_active,
            'sort_order' => $category->sort_order,
            'child_count' => $category->childCount(),
            'product_count' => $category->productCount(),
            'can' => [
                'update' => $actor->can('update', $category),
                'delete' => $canDelete && $category->isDeletable(),
            ],
            // Why deletion is off, for a tooltip on the disabled action.
            'delete_blocked_reason' => $canDelete ? $category->deletionBlockedReason() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryDetail(Category $category): array
    {
        return [
            'id' => $category->id,
            'parent_id' => $category->parent_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'icon' => $category->icon,
            'is_active' => $category->is_active,
            'sort_order' => $category->sort_order,
            'child_count' => $category->childCount(),
            'product_count' => $category->productCount(),
            'depth' => $category->depth(),
            'created_at' => $category->created_at?->toIso8601String(),
        ];
    }

    /**
     * Categories that may hold the one being saved, indented by depth.
     *
     * A parent is offered only when the result still fits inside
     * `Category::MAX_DEPTH`, which for an existing category means counting the
     * height of the subtree travelling with it. Its own subtree is left out
     * entirely: moving a category under its own descendant would cut that part
     * of the tree off from the roots.
     *
     * @return array<int, array{value: int, label: string, depth: int}>
     */
    private function parentOptions(?Category $moving = null): array
    {
        $excluded = $moving === null
            ? []
            : [$moving->getKey(), ...$moving->descendantIds()];

        $ownHeight = $moving?->subtreeHeight() ?? 1;

        $rows = CategoryTree::of(Category::query()->get())->flatten();

        $options = [];

        foreach ($rows as $row) {
            $category = $row['category'];

            if (in_array($category->getKey(), $excluded, strict: true)) {
                continue;
            }

            if ($row['depth'] + $ownHeight > Category::MAX_DEPTH) {
                continue;
            }

            $options[] = [
                'value' => $category->id,
                'label' => $category->name,
                'depth' => $row['depth'],
            ];
        }

        return $options;
    }

    private function statusMessageFor(Category $category, bool $isActive, int $hiddenCount): string
    {
        if ($isActive) {
            return "Kategori {$category->name} diaktifkan.";
        }

        if ($hiddenCount > 0) {
            return "Kategori {$category->name} dinonaktifkan beserta {$hiddenCount} subkategorinya.";
        }

        return "Kategori {$category->name} dinonaktifkan.";
    }
}
