<?php

namespace App\Http\Requests\Shop;

use App\Support\CatalogRules;
use App\Support\StorefrontCatalogQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates and normalises the storefront catalogue's query string
 * (P1-E5-T3, T4, T5).
 *
 * The sort and availability values are whitelisted against
 * `StorefrontCatalogQuery`'s own lists, so the screen's select, the validation
 * and the `ORDER BY` can never drift apart — and no arbitrary value reaches
 * the query.
 *
 * The category travels as a **slug**, not an id, because it is the same value
 * the `/kategori/{slug}` route carries and a shared link should stay readable.
 * It is deliberately not checked against the table here: an unknown slug is a
 * stale link, which the query answers with an empty grid, not a validation
 * error on a page a shopper only wanted to browse.
 */
class IndexCatalogRequest extends FormRequest
{
    /**
     * The storefront catalogue is public.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:255'],
            'min_price' => ['nullable', 'integer', 'min:0', 'max:'.CatalogRules::MAX_PRICE],
            'max_price' => ['nullable', 'integer', 'min:0', 'max:'.CatalogRules::MAX_PRICE],
            'availability' => ['nullable', 'string', Rule::in(array_keys(StorefrontCatalogQuery::AVAILABILITY))],
            'sort' => ['nullable', 'string', Rule::in(array_keys(StorefrontCatalogQuery::SORTS))],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'search' => 'kata pencarian',
            'category' => 'kategori',
            'min_price' => 'harga minimum',
            'max_price' => 'harga maksimum',
            'availability' => 'ketersediaan',
            'sort' => 'urutan',
        ];
    }

    /**
     * @return array{search: string, category: string, min_price: string, max_price: string, availability: string, sort: string}
     */
    public function filters(): array
    {
        /** @var array<string, string|int|null> $validated */
        $validated = $this->validated();

        return [
            'search' => trim((string) ($validated['search'] ?? '')),
            'category' => (string) ($validated['category'] ?? ''),
            'min_price' => (string) ($validated['min_price'] ?? ''),
            'max_price' => $this->upperBound($validated),
            'availability' => (string) ($validated['availability'] ?? ''),
            'sort' => (string) ($validated['sort'] ?? StorefrontCatalogQuery::DEFAULT_SORT),
        ];
    }

    /**
     * The maximum price, dropped when it falls below the minimum.
     *
     * Dropped rather than refused: a price slider the shopper is still
     * dragging passes through this range on its way somewhere sensible, and an
     * error message on a half-typed number is noise. The result is the same
     * grid they would see with only the minimum set.
     *
     * @param  array<string, string|int|null>  $validated
     */
    private function upperBound(array $validated): string
    {
        $min = $validated['min_price'] ?? null;
        $max = $validated['max_price'] ?? null;

        if ($max === null || ($min !== null && (int) $max < (int) $min)) {
            return '';
        }

        return (string) $max;
    }
}
