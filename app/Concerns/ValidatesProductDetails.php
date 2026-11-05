<?php

namespace App\Concerns;

use App\Support\CatalogRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Shared validation for a product's own fields (P1-E3-T5).
 *
 * Create and edit submit the same fields, so the rules live in one place.
 * What differs is the slug (derived on create) and the uniqueness checks,
 * which have to ignore the record being edited.
 *
 * The rules themselves come from `App\Support\CatalogRules`, which the CSV
 * import also reads (P1-E3-T11) — a product the panel would refuse must not
 * get in through a spreadsheet, and vice versa.
 *
 * Prices arrive as whole rupiah integers (ADR-008, BR-10): the form sends
 * digits, never a decimal, and the columns are `unsignedBigInteger`.
 *
 * @phpstan-require-extends FormRequest
 */
trait ValidatesProductDetails
{
    /** The largest rupiah amount a price field accepts, to keep typos out. */
    public const MAX_PRICE = CatalogRules::MAX_PRICE;

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function productDetailRules(): array
    {
        return CatalogRules::productDetails();
    }

    /**
     * @return array<string, string>
     */
    protected function productDetailAttributes(): array
    {
        return CatalogRules::productAttributeNames();
    }

    /**
     * @return array<string, string>
     */
    protected function productDetailMessages(): array
    {
        return CatalogRules::productMessages();
    }

    /**
     * A strikethrough price that is not actually higher than the selling price
     * advertises a discount that does not exist.
     */
    protected function requireCompareAtPriceAboveBasePrice(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['base_price', 'compare_at_price'])) {
            return;
        }

        $compareAt = $this->input('compare_at_price');

        if ($compareAt === null || $compareAt === '') {
            return;
        }

        if (CatalogRules::compareAtPriceIsAboveBasePrice((int) $compareAt, $this->integer('base_price'))) {
            return;
        }

        $validator->errors()->add(
            'compare_at_price',
            'Harga sebelum diskon harus lebih tinggi dari harga jual, atau dibiarkan kosong.',
        );
    }

    /**
     * Prices and dimensions are NOT NULL or constrained in the database, but an
     * emptied number input arrives as null (Laravel converts empty strings).
     * The required ones are normalised to a usable default rather than refused
     * with a message about a field the user never filled in; the optional ones
     * stay null.
     */
    protected function normalizeProductNumbers(): void
    {
        foreach (['base_price' => 0, 'weight_gram' => 0] as $field => $default) {
            if ($this->input($field) === null || $this->input($field) === '') {
                $this->merge([$field => $default]);
            }
        }
    }
}
