import { Search, SlidersHorizontal, X } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import type {
    CatalogCategoryOption,
    CatalogFilters,
    CatalogOptions,
} from '@/types';

/** Radix refuses an empty `SelectItem` value, so "no filter" carries a sentinel. */
const ALL_VALUE = '__all__';

const SEARCH_DEBOUNCE_MS = 300;

type Props = {
    filters: CatalogFilters;
    options: CatalogOptions;
    /** Applies the changed keys and returns to page 1. */
    onChange: (next: Record<string, string | null>) => void;
    onReset: () => void;
    isFiltered: boolean;
    /** True while the category comes from the URL and cannot be changed here. */
    categoryIsFixed?: boolean;
};

/**
 * The catalogue's filters (P1-E5-T4, T5).
 *
 * Drawn as a sidebar rather than a toolbar: a shopper narrows by several
 * things at once — category, price, availability — and a row of selects that
 * wraps onto three lines on a phone is harder to read than a column.
 *
 * `FilterBar` is the panels' toolbar and stays theirs (ADR-013): it is built
 * for a table's one-line "search + a few selects", and a price range with two
 * inputs and an availability checkbox do not fit that shape.
 *
 * The search box is debounced, so typing narrows the grid without one request
 * per keystroke. Everything else applies on change, because every control here
 * is a single decision.
 */
export function CatalogFilters({
    filters,
    options,
    onChange,
    onReset,
    isFiltered,
    categoryIsFixed = false,
}: Props) {
    return (
        <aside
            aria-label="Filter produk"
            className="flex flex-col gap-5 rounded-xl border p-4"
        >
            <div className="flex items-center justify-between gap-2">
                <h2 className="inline-flex items-center gap-2 text-sm font-semibold">
                    <SlidersHorizontal className="size-4" aria-hidden />
                    Filter
                </h2>
                {isFiltered && (
                    <Button variant="ghost" size="sm" onClick={onReset}>
                        <X aria-hidden />
                        Reset
                    </Button>
                )}
            </div>

            <SearchField
                value={filters.search}
                onChange={(value) => onChange({ search: value || null })}
            />

            <Separator />

            {!categoryIsFixed && (
                <>
                    <CategoryField
                        value={filters.category}
                        categories={options.categories}
                        onChange={(value) =>
                            onChange({ category: value || null })
                        }
                    />
                    <Separator />
                </>
            )}

            <PriceRangeField
                min={filters.min_price}
                max={filters.max_price}
                onChange={onChange}
            />

            <Separator />

            <AvailabilityField
                value={filters.availability}
                options={options.availability}
                onChange={(value) => onChange({ availability: value })}
            />
        </aside>
    );
}

function SearchField({
    value,
    onChange,
}: {
    value: string;
    onChange: (value: string) => void;
}) {
    const inputId = useId();
    const [draft, setDraft] = useState(value);

    // Follow the URL when it changes elsewhere: the reset button, the back
    // button, or the search box in the header.
    useEffect(() => setDraft(value), [value]);

    useEffect(() => {
        if (draft === value) {
            return;
        }

        const timeout = setTimeout(() => onChange(draft), SEARCH_DEBOUNCE_MS);

        return () => clearTimeout(timeout);
        // `onChange` is rebuilt on every render by the caller, so it is left out on
        // purpose; the draft and the applied value decide when to fire.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [draft, value]);

    return (
        <div className="flex flex-col gap-2">
            <Label htmlFor={inputId} className="text-xs text-muted-foreground">
                Cari di katalog
            </Label>
            <div className="relative">
                <Search
                    aria-hidden
                    className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    id={inputId}
                    type="search"
                    value={draft}
                    placeholder="Nama produk…"
                    onChange={(event) => setDraft(event.target.value)}
                    className="pl-8"
                />
            </div>
        </div>
    );
}

function CategoryField({
    value,
    categories,
    onChange,
}: {
    value: string;
    categories: CatalogCategoryOption[];
    onChange: (value: string) => void;
}) {
    return (
        <div className="flex flex-col gap-2">
            <Label className="text-xs text-muted-foreground">Kategori</Label>
            <Select
                value={value === '' ? ALL_VALUE : value}
                onValueChange={(next) =>
                    onChange(next === ALL_VALUE ? '' : next)
                }
            >
                <SelectTrigger aria-label="Kategori">
                    <SelectValue placeholder="Semua kategori" />
                </SelectTrigger>
                <SelectContent>
                    <SelectGroup>
                        <SelectItem value={ALL_VALUE}>
                            Semua kategori
                        </SelectItem>
                        {categories.map((category) => (
                            <SelectItem
                                key={category.value}
                                value={category.value}
                            >
                                {'— '.repeat(Math.max(category.depth - 1, 0))}
                                {category.label}
                            </SelectItem>
                        ))}
                    </SelectGroup>
                </SelectContent>
            </Select>
        </div>
    );
}

/**
 * The price range, applied on blur rather than on change.
 *
 * Half a typed number is a different range: applying "1" on the way to
 * "100000" would empty the grid and then refill it. The backend drops a
 * maximum below the minimum for the same reason.
 */
function PriceRangeField({
    min,
    max,
    onChange,
}: {
    min: string;
    max: string;
    onChange: (next: Record<string, string | null>) => void;
}) {
    const minId = useId();
    const maxId = useId();
    const [draftMin, setDraftMin] = useState(min);
    const [draftMax, setDraftMax] = useState(max);

    useEffect(() => setDraftMin(min), [min]);
    useEffect(() => setDraftMax(max), [max]);

    const apply = (): void => {
        if (draftMin === min && draftMax === max) {
            return;
        }

        onChange({
            min_price: draftMin || null,
            max_price: draftMax || null,
        });
    };

    return (
        <div className="flex flex-col gap-2">
            <Label className="text-xs text-muted-foreground">
                Rentang harga (Rp)
            </Label>
            <div className="flex items-center gap-2">
                <Input
                    id={minId}
                    type="number"
                    min={0}
                    inputMode="numeric"
                    value={draftMin}
                    placeholder="Min"
                    aria-label="Harga minimum"
                    onChange={(event) => setDraftMin(event.target.value)}
                    onBlur={apply}
                />
                <span className="text-muted-foreground">–</span>
                <Input
                    id={maxId}
                    type="number"
                    min={0}
                    inputMode="numeric"
                    value={draftMax}
                    placeholder="Maks"
                    aria-label="Harga maksimum"
                    onChange={(event) => setDraftMax(event.target.value)}
                    onBlur={apply}
                />
            </div>
        </div>
    );
}

function AvailabilityField({
    value,
    options,
    onChange,
}: {
    value: string;
    options: CatalogOptions['availability'];
    onChange: (value: string | null) => void;
}) {
    return (
        <div className="flex flex-col gap-2">
            <Label className="text-xs text-muted-foreground">
                Ketersediaan
            </Label>
            {options.map((option) => (
                <label
                    key={option.value}
                    className="flex cursor-pointer items-center gap-2 text-sm"
                >
                    <Checkbox
                        checked={value === option.value}
                        onCheckedChange={(checked) =>
                            onChange(checked === true ? option.value : null)
                        }
                    />
                    {option.label}
                </label>
            ))}
        </div>
    );
}
