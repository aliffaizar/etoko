<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProductAttributeType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductAttributeRequest;
use App\Http\Requests\Admin\UpdateProductAttributeRequest;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The attributes variants are built from (P1-E3-T6).
 *
 * One screen holds every attribute with its values, because the list is short
 * by nature — a catalogue has a handful of axes, not hundreds — and an
 * attribute is only legible next to the options it offers. There is no
 * paginator and no create page for the same reason; values are edited in place,
 * the way a branch's warehouses are.
 *
 * Attributes are catalogue-wide, not per product (ADR-021): renaming "Merah"
 * renames it in every product that uses it. That is the point — one colour
 * filter works across categories — and it is also why writes demand a central
 * actor and why an attribute in use cannot be deleted.
 */
class ProductAttributeController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', ProductAttribute::class);

        /** @var User $actor */
        $actor = request()->user();

        return Inertia::render('admin/product-attributes/index', [
            'attributes' => $this->attributeList($actor),
            'options' => ['types' => ProductAttributeType::options()],
            'can' => ['create' => $actor->can('create', ProductAttribute::class)],
        ]);
    }

    public function store(StoreProductAttributeRequest $request): RedirectResponse
    {
        $this->authorize('create', ProductAttribute::class);

        $attribute = ProductAttribute::create($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Atribut {$attribute->name} berhasil ditambahkan.",
        ]);

        return back();
    }

    public function update(UpdateProductAttributeRequest $request, ProductAttribute $attribute): RedirectResponse
    {
        $this->authorize('update', $attribute);

        $attribute->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Perubahan atribut berhasil disimpan.']);

        return back();
    }

    /**
     * Removes an attribute together with its values.
     *
     * An attribute still defining variants stays: its values cascade, which
     * would empty the pivot and leave two variants of the same product
     * indistinguishable.
     */
    public function destroy(ProductAttribute $attribute): RedirectResponse
    {
        $this->authorize('delete', $attribute);

        $reason = $attribute->deletionBlockedReason();

        abort_if($reason !== null, 403, (string) $reason);

        $attribute->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Atribut {$attribute->name} dihapus."]);

        return back();
    }

    /**
     * Every attribute with its values, ordered, plus how many variants each
     * one is holding.
     *
     * The usage count is summed from the values that are already loaded and
     * handed back to the model, so `isDeletable()` answers from it instead of
     * running its own query per row.
     *
     * @return array<int, array<string, mixed>>
     */
    private function attributeList(User $actor): array
    {
        $attributes = ProductAttribute::query()
            ->with(['values' => fn (HasMany $values) => $values->ordered()->withCount(['variants'])])
            ->ordered()
            ->get();

        return $attributes->map(function (ProductAttribute $attribute) use ($actor): array {
            $attribute->setAttribute(
                'variant_usage_count',
                $attribute->values->sum(fn (ProductAttributeValue $value): int => $value->variantCount()),
            );

            return [
                'id' => $attribute->id,
                'name' => $attribute->name,
                'slug' => $attribute->slug,
                'type' => $attribute->type->value,
                'type_label' => $attribute->type->label(),
                'uses_color_swatch' => $attribute->type->usesColorSwatch(),
                'sort_order' => $attribute->sort_order,
                'variant_usage_count' => $attribute->variantUsageCount(),
                'deletion_blocked_reason' => $attribute->deletionBlockedReason(),
                'values' => $attribute->values
                    ->map(fn (ProductAttributeValue $value): array => [
                        'id' => $value->id,
                        'value' => $value->value,
                        'label' => $value->label,
                        'display_label' => $value->displayLabel(),
                        'color_hex' => $value->color_hex,
                        'sort_order' => $value->sort_order,
                        'variant_count' => $value->variantCount(),
                    ])
                    ->all(),
                'can' => [
                    'update' => $actor->can('update', $attribute),
                    'delete' => $actor->can('delete', $attribute) && $attribute->isDeletable(),
                ],
            ];
        })->all();
    }
}
