<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Product\CreateProductVariant;
use App\Actions\Product\GenerateProductVariants;
use App\Actions\Product\SetDefaultProductVariant;
use App\Actions\Product\UpdateProductVariant;
use App\Enums\ProductType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GenerateProductVariantsRequest;
use App\Http\Requests\Admin\StoreProductVariantRequest;
use App\Http\Requests\Admin\UpdateProductVariantRequest;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\AttributeCombinations;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The variant editor of one product (P1-E3-T6).
 *
 * Variants have no screen of their own: they are managed on the product they
 * belong to, so the routes are nested and authorisation asks `ProductPolicy`
 * about the product. Scoped route bindings make a variant of another product a
 * 404 rather than a silent cross-product edit.
 *
 * Three things the screen can do, in the order they are normally reached for:
 * generate a grid of variants from the options a product varies by, add the
 * odd combination the grid would not produce, and edit one variant's SKU,
 * barcode, price and weight. Price, weight and dimensions are overrides —
 * empty means "follow the product" (ADR-020).
 *
 * A `simple` product keeps its single default variant: giving it more is a
 * decision about the product's type, made on the product form (ADR-022).
 */
class ProductVariantController extends Controller
{
    public function index(Product $product): Response
    {
        $this->authorize('view', $product);

        /** @var User $actor */
        $actor = request()->user();
        $canUpdate = $actor->can('update', $product);

        return Inertia::render('admin/products/variants', [
            'product' => $this->productSummary($product),
            'variants' => $this->variantList($product, $canUpdate),
            'options' => ['attributes' => $this->attributeOptions()],
            'can' => [
                'update' => $canUpdate,
                'generate' => $canUpdate && $product->type === ProductType::Variable,
            ],
            'limits' => ['max_combinations' => AttributeCombinations::MAX_COMBINATIONS],
        ]);
    }

    /**
     * Creates every variant the chosen options imply and does not exist yet.
     */
    public function generate(
        GenerateProductVariantsRequest $request,
        Product $product,
        GenerateProductVariants $generateVariants,
    ): RedirectResponse {
        $this->authorize('update', $product);
        $this->refuseVariantsOnSimpleProducts($product);

        $created = $generateVariants->handle($product, $request->valueIdGroups());

        Inertia::flash('toast', [
            'type' => $created->isEmpty() ? 'info' : 'success',
            'message' => $created->isEmpty()
                ? 'Semua kombinasi dari pilihan tersebut sudah ada. Tidak ada varian baru yang dibuat.'
                : "{$created->count()} varian berhasil dibuat.",
        ]);

        return back();
    }

    public function store(
        StoreProductVariantRequest $request,
        Product $product,
        CreateProductVariant $createVariant,
    ): RedirectResponse {
        $this->authorize('update', $product);
        $this->refuseVariantsOnSimpleProducts($product);

        $variant = $createVariant->handle($product, $request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Varian {$variant->displayName()} berhasil ditambahkan.",
        ]);

        return back();
    }

    public function update(
        UpdateProductVariantRequest $request,
        Product $product,
        ProductVariant $variant,
        UpdateProductVariant $updateVariant,
    ): RedirectResponse {
        $this->authorize('update', $product);

        $updateVariant->handle($variant, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Perubahan varian berhasil disimpan.']);

        return back();
    }

    public function setDefault(
        Product $product,
        ProductVariant $variant,
        SetDefaultProductVariant $setDefaultVariant,
    ): RedirectResponse {
        $this->authorize('update', $product);

        $setDefaultVariant->handle($variant);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Varian {$variant->displayName()} kini menjadi varian utama produk ini.",
        ]);

        return back();
    }

    /**
     * Soft deletes a variant, keeping the order history that points at it.
     *
     * The default variant and the product's last variant both stay; the reason
     * comes from the model, which is also what the editor's delete button
     * reads.
     */
    public function destroy(Product $product, ProductVariant $variant): RedirectResponse
    {
        $this->authorize('update', $product);

        $variant->setRelation('product', $product);
        $reason = $variant->deletionBlockedReason();

        abort_if($reason !== null, 403, (string) $reason);

        $variant->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Varian {$variant->displayName()} dihapus."]);

        return back();
    }

    /**
     * A `simple` product is sold as one thing; it still owns exactly one
     * variant because stock and order lines point at variants (ADR-020), but
     * giving it a second one is a change of type and belongs on the product
     * form (ADR-022).
     */
    private function refuseVariantsOnSimpleProducts(Product $product): void
    {
        abort_if(
            $product->type !== ProductType::Variable,
            403,
            'Produk sederhana hanya punya satu varian. Ubah tipe produk menjadi "Produk Bervarian" lebih dahulu untuk menambah varian.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function productSummary(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'type' => $product->type->value,
            'type_label' => $product->type->label(),
            'base_price' => $product->base_price,
            'compare_at_price' => $product->compare_at_price,
            'cost_price' => $product->cost_price,
            'weight_gram' => $product->weight_gram,
            'length_cm' => $product->length_cm,
            'width_cm' => $product->width_cm,
            'height_cm' => $product->height_cm,
        ];
    }

    /**
     * Every variant of the product with the options that define it and the
     * prices and weights actually in force.
     *
     * The effective figures are sent alongside the raw overrides so the editor
     * can show what a null falls back to without duplicating the rule.
     *
     * @return array<int, array<string, mixed>>
     */
    private function variantList(Product $product, bool $canUpdate): array
    {
        $variants = $product->variants()
            ->with(['attributeValues.attribute:id,name,type,sort_order'])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        // Lets `ProductVariant::deletionBlockedReason()` read the sibling count
        // off the product it already has, instead of counting per row.
        $product->setAttribute('variants_count', $variants->count());

        return $variants->map(function (ProductVariant $variant) use ($product, $canUpdate): array {
            $variant->setRelation('product', $product);
            $blockedReason = $variant->deletionBlockedReason();

            return [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'name' => $variant->name,
                'display_name' => $variant->displayName(),
                'price' => $variant->price,
                'compare_at_price' => $variant->compare_at_price,
                'cost_price' => $variant->cost_price,
                'weight_gram' => $variant->weight_gram,
                'length_cm' => $variant->length_cm,
                'width_cm' => $variant->width_cm,
                'height_cm' => $variant->height_cm,
                'effective_price' => $variant->effectivePrice(),
                'effective_compare_at_price' => $variant->effectiveCompareAtPrice(),
                'effective_weight_gram' => $variant->effectiveWeightGram(),
                'is_active' => $variant->is_active,
                'is_default' => $variant->is_default,
                'attribute_value_ids' => $variant->attributeValues->pluck('id')->all(),
                'options' => $variant->attributeValues
                    ->sortBy([['attribute.sort_order', 'asc'], ['sort_order', 'asc']])
                    ->values()
                    ->map(fn (ProductAttributeValue $value): array => [
                        'attribute_id' => $value->product_attribute_id,
                        'attribute_name' => $value->attribute->name,
                        'value_id' => $value->id,
                        'label' => $value->displayLabel(),
                        'color_hex' => $value->color_hex,
                    ])
                    ->all(),
                'can' => [
                    'update' => $canUpdate,
                    'delete' => $canUpdate && $blockedReason === null,
                    'make_default' => $canUpdate && ! $variant->is_default,
                ],
                'deletion_blocked_reason' => $blockedReason,
            ];
        })->all();
    }

    /**
     * Every attribute with its values, for the generator's pickers.
     *
     * Ordered the way the attribute screen orders them, so the groups the user
     * builds read in the same sequence the generated names will.
     *
     * @return array<int, array<string, mixed>>
     */
    private function attributeOptions(): array
    {
        return ProductAttribute::query()
            ->with(['values' => fn (HasMany $values) => $values->ordered()])
            ->ordered()
            ->get()
            ->map(fn (ProductAttribute $attribute): array => [
                'id' => $attribute->id,
                'name' => $attribute->name,
                'type' => $attribute->type->value,
                'uses_color_swatch' => $attribute->type->usesColorSwatch(),
                'values' => $attribute->values
                    ->map(fn (ProductAttributeValue $value): array => [
                        'id' => $value->id,
                        'label' => $value->displayLabel(),
                        'color_hex' => $value->color_hex,
                    ])
                    ->all(),
            ])
            ->all();
    }
}
