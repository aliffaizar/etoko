<?php

namespace App\Actions\Product;

use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Support\AttributeCombinations;
use App\Support\VariantIdentity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Creates the variants a product's chosen options imply (P1-E3-T6).
 *
 * Picking Warna = Merah, Biru and Ukuran = M, L should not mean keying in four
 * variants by hand, so this expands the options into every combination and
 * creates the ones the product does not have yet. SKUs and names are derived
 * from the product's own SKU and the option labels, and stay editable
 * afterwards.
 *
 * Combinations the product already carries are left exactly as they are. That
 * is what makes generating safe to repeat: adding one more colour to the list
 * and generating again adds that colour's variants and touches nothing else —
 * no price someone typed is overwritten, no SKU is renumbered.
 *
 * Prices, weights and dimensions of the new variants stay null, so they follow
 * the product's until someone sets them (ADR-020).
 */
final class GenerateProductVariants
{
    /**
     * @param  array<int, array<int, int>>  $valueIdGroups  Attribute value ids, one inner list per attribute, in the order they should read.
     * @return Collection<int, ProductVariant> The variants this call created or adopted.
     */
    public function handle(Product $product, array $valueIdGroups): Collection
    {
        $values = $this->loadValues($valueIdGroups);
        $combinations = AttributeCombinations::cartesian($valueIdGroups);

        return DB::transaction(function () use ($product, $combinations, $values): Collection {
            $existing = $this->existingFingerprints($product);
            $adoptable = $this->adoptableVariant($product);
            $touched = new Collection;
            $claimedSkus = [];
            $hasDefault = $product->variants()->where('is_default', true)->exists();

            foreach ($combinations as $valueIds) {
                $fingerprint = AttributeCombinations::fingerprint($valueIds);

                if (in_array($fingerprint, $existing, true)) {
                    continue;
                }

                $labels = $this->labelsFor($valueIds, $values);

                $variant = $adoptable instanceof ProductVariant
                    ? $this->adopt($adoptable, $labels)
                    : $this->create($product, $labels, $claimedSkus, $hasDefault);

                $adoptable = null;

                $variant->attributeValues()->sync($valueIds);

                $existing[] = $fingerprint;
                $touched->push($variant);
            }

            return $touched;
        });
    }

    /**
     * The bare variant a product carries before it has any options, if that is
     * all it has.
     *
     * Every product is created with one default variant (ADR-020), and for a
     * product being given options that variant holds the SKU someone typed on
     * the product form. Generating takes it over as the first combination
     * rather than leaving an optionless variant beside the real ones, which
     * neither the storefront nor a POS screen could tell apart.
     */
    private function adoptableVariant(Product $product): ?ProductVariant
    {
        if ($product->variants()->withTrashed()->count() !== 1) {
            return null;
        }

        $variant = $product->variants()->with('attributeValues:id')->first();

        if (! $variant instanceof ProductVariant || $variant->attributeValues->isNotEmpty()) {
            return null;
        }

        return $variant;
    }

    /**
     * @param  array<int, string>  $labels
     */
    private function adopt(ProductVariant $variant, array $labels): ProductVariant
    {
        $variant->fill(['name' => VariantIdentity::nameFrom($labels)])->save();

        return $variant;
    }

    /**
     * @param  array<int, string>  $labels
     * @param  array<int, string>  $claimedSkus  SKUs this run has handed out but not committed yet.
     * @param  bool  $hasDefault  Whether the product already has its default variant; set once this call supplies one.
     */
    private function create(Product $product, array $labels, array &$claimedSkus, bool &$hasDefault): ProductVariant
    {
        $sku = VariantIdentity::skuFrom($this->skuBaseFor($product), $labels, $claimedSkus);
        $claimedSkus[] = $sku;

        $variant = $product->variants()->create([
            'sku' => $sku,
            'name' => VariantIdentity::nameFrom($labels),
            'is_active' => true,
            // A product left without a default variant gets the first one
            // generated, so there is always the fallback every storefront and
            // POS screen reads (ADR-020).
            'is_default' => ! $hasDefault,
        ]);

        $hasDefault = true;

        return $variant;
    }

    /**
     * The attribute values involved, keyed by id.
     *
     * @param  array<int, array<int, int>>  $valueIdGroups
     * @return Collection<int, ProductAttributeValue>
     */
    private function loadValues(array $valueIdGroups): Collection
    {
        $ids = array_values(array_unique(array_merge([], ...array_values($valueIdGroups))));

        /** @var Collection<int, ProductAttributeValue> $values */
        $values = ProductAttributeValue::query()->whereIn('id', $ids)->get()->keyBy('id');

        return $values;
    }

    /**
     * Fingerprints of the combinations the product already carries, so a
     * repeated generation adds only what is missing.
     *
     * Soft-deleted variants count: their SKU and their combination are still
     * taken, and reviving one belongs to the variant list, not here.
     *
     * @return array<int, string>
     */
    private function existingFingerprints(Product $product): array
    {
        return $product->variants()
            ->withTrashed()
            ->with('attributeValues:id')
            ->get()
            ->map(fn (ProductVariant $variant): string => AttributeCombinations::fingerprint(
                $variant->attributeValues->pluck('id')->all(),
            ))
            ->all();
    }

    /**
     * @param  array<int, int>  $valueIds
     * @param  Collection<int, ProductAttributeValue>  $values
     * @return array<int, string>
     */
    private function labelsFor(array $valueIds, Collection $values): array
    {
        return array_values(array_filter(array_map(
            fn (int $id): ?string => $values->get($id)?->displayLabel(),
            $valueIds,
        )));
    }

    /**
     * The product's default variant SKU is what generated SKUs are built on,
     * falling back to the product's name when there is no default variant.
     */
    private function skuBaseFor(Product $product): string
    {
        $default = $product->variants()->where('is_default', true)->value('sku');

        return (string) ($default ?? $product->name);
    }
}
