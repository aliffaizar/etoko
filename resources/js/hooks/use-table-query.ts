import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import type { SortDirection, TableSort } from '@/types/data-table';

/** Query keys the table itself owns; everything else counts as a filter. */
const RESERVED_KEYS = ['page', 'sort', 'direction'];

export type TableQueryValue = string | number | boolean | null | undefined;

export type UseTableQueryOptions = {
    /**
     * Prop names to reload, so changing a filter does not re-resolve the whole
     * page. Leave empty to reload every prop.
     */
    only?: string[];
    /** Default sort column, used when the URL carries none. */
    defaultSort?: string;
    defaultDirection?: SortDirection;
    /**
     * Query keys that say *where* the listing is looking rather than *what* it
     * is narrowed to — the branch and warehouse of the stock screens, for
     * instance.
     *
     * They survive `reset()` and do not make `isFiltered` true, because
     * "reset the filters" must not quietly move somebody to another
     * warehouse, and standing in a warehouse is not a filter to clear.
     */
    scopeKeys?: string[];
};

export type UseTableQueryReturn = {
    /** The current query string as a flat map, e.g. `{ search: 'ani', role: 'kasir' }`. */
    values: Record<string, string>;
    /** Replaces the given keys and returns to page 1. */
    setFilters: (next: Record<string, TableQueryValue>) => void;
    sort: TableSort;
    /** Sorts by the column, flipping the direction when it is already sorted. */
    toggleSort: (column: string) => void;
    goToPage: (page: number) => void;
    /** Absolute URL for a page, so pagination links stay real links. */
    pageUrl: (page: number) => string;
    /** Clears every filter, sort and page. */
    reset: () => void;
    /** Whether any filter (not sort or page) is applied. */
    isFiltered: boolean;
    /** True while a table-driven visit is in flight. */
    isLoading: boolean;
};

/**
 * Keeps a listing's filters, sort and page in the URL and reloads the page
 * props through Inertia when they change.
 *
 * The URL is the single source of truth, so a filtered listing can be
 * bookmarked, shared and restored by the browser's back button. Every panel
 * listing uses this hook together with `FilterBar` and `DataTable`.
 */
export function useTableQuery({
    only,
    defaultSort,
    defaultDirection = 'asc',
    scopeKeys = [],
}: UseTableQueryOptions = {}): UseTableQueryReturn {
    const url = usePage().url;
    const [isLoading, setIsLoading] = useState(false);

    const [path, search] = useMemo(() => {
        const separator = url.indexOf('?');

        return separator === -1
            ? [url, '']
            : [url.slice(0, separator), url.slice(separator + 1)];
    }, [url]);

    const values = useMemo(
        () => Object.fromEntries(new URLSearchParams(search)),
        [search],
    );

    // A visit started elsewhere (a link, the back button) ends this hook's
    // loading state too, otherwise the table could stay dimmed.
    useEffect(() => setIsLoading(false), [url]);

    const buildQuery = useCallback(
        (next: Record<string, TableQueryValue>): Record<string, string> => {
            const merged: Record<string, TableQueryValue> = {
                ...values,
                ...next,
            };

            return Object.fromEntries(
                Object.entries(merged)
                    .filter(
                        ([, value]) =>
                            value !== null &&
                            value !== undefined &&
                            value !== '',
                    )
                    .map(([key, value]) => [key, String(value)]),
            );
        },
        [values],
    );

    const visit = useCallback(
        (next: Record<string, TableQueryValue>): void => {
            router.get(path, buildQuery(next), {
                only,
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setIsLoading(true),
                onFinish: () => setIsLoading(false),
            });
        },
        [buildQuery, only, path],
    );

    const sort = useMemo<TableSort>(
        () => ({
            column: values.sort ?? defaultSort ?? null,
            direction:
                values.direction === 'desc'
                    ? 'desc'
                    : values.direction === 'asc'
                      ? 'asc'
                      : defaultDirection,
        }),
        [defaultDirection, defaultSort, values.direction, values.sort],
    );

    const ignoredKeys = useMemo(
        () => [...RESERVED_KEYS, ...scopeKeys],
        // `scopeKeys` is a literal at every call site, so its identity changes
        // each render while its contents do not.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [scopeKeys.join(',')],
    );

    const isFiltered = useMemo(
        () =>
            Object.entries(values).some(
                ([key, value]) => !ignoredKeys.includes(key) && value !== '',
            ),
        [ignoredKeys, values],
    );

    return {
        values,
        isLoading,
        sort,
        isFiltered,
        setFilters: (next) => visit({ ...next, page: null }),
        toggleSort: (column) =>
            visit({
                sort: column,
                direction:
                    sort.column === column && sort.direction === 'asc'
                        ? 'desc'
                        : 'asc',
                page: null,
            }),
        goToPage: (page) => visit({ page: page <= 1 ? null : page }),
        pageUrl: (page) => {
            const query = new URLSearchParams(
                buildQuery({ page: page <= 1 ? null : page }),
            ).toString();

            return query === '' ? path : `${path}?${query}`;
        },
        reset: () =>
            router.get(
                path,
                Object.fromEntries(
                    Object.entries(values).filter(([key]) =>
                        scopeKeys.includes(key),
                    ),
                ),
                {
                    only,
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                    onStart: () => setIsLoading(true),
                    onFinish: () => setIsLoading(false),
                },
            ),
    };
}
