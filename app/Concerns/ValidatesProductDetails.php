<?php

namespace App\Concerns;

use App\Support\CatalogRules;
use DateTimeInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
     * How the form says a product should stand towards buyers.
     *
     * `published_at` alone could express all three, but only if whoever fills
     * the form already knows that an empty date means "draft" and that
     * publishing means typing today's date. Asking the question directly is
     * what makes publishing findable at all.
     */
    public const PUBLICATION_MODES = ['draft', 'published', 'scheduled'];

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function productDetailRules(): array
    {
        return [
            ...CatalogRules::productDetails(),
            // `sometimes`: the CSV import and any other caller still set
            // `published_at` directly, and must keep working untouched.
            'publication_mode' => ['sometimes', Rule::in(self::PUBLICATION_MODES)],
        ];
    }

    /**
     * Turns the form's publication choice into a `published_at`.
     *
     * The three modes are the states the badge already names — Draf, Terbit,
     * Terjadwal — so the form asks the question the user is actually
     * answering instead of making them derive it from a date field.
     *
     * "Terbit" resolves on the **server**, so a browser whose clock or
     * timezone differs cannot publish a product into the future and leave it
     * silently scheduled — the exact confusion this control exists to remove.
     *
     * An already-published product **keeps its original release moment**:
     * re-saving the form must not restamp the date a product went live, or
     * "terbit sejak" would quietly become "terakhir disunting".
     *
     * A request that sends no `publication_mode` is left exactly as it is, so
     * the CSV import and any other caller that sets `published_at` directly
     * keep working.
     *
     * @param  DateTimeInterface|null  $current  The product's current `published_at`, when editing one.
     */
    protected function resolvePublishedAt(?DateTimeInterface $current = null): void
    {
        if (! $this->has('publication_mode')) {
            return;
        }

        $publishedAt = match ((string) $this->input('publication_mode')) {
            'draft' => null,
            // Typed as DateTimeInterface, not Carbon: the project casts
            // datetimes to CarbonImmutable.
            'published' => $current !== null && $current->getTimestamp() <= now()->getTimestamp()
                ? $current->format('Y-m-d H:i:s')
                : now()->format('Y-m-d H:i:s'),
            default => $this->input('published_at'),
        };

        $this->merge(['published_at' => $publishedAt]);
    }

    /**
     * The validated product fields, without the helper that only told us how
     * to read `published_at`.
     *
     * @return array<string, mixed>
     */
    protected function productAttributes(): array
    {
        $attributes = $this->validated();

        unset($attributes['publication_mode']);

        return $attributes;
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
