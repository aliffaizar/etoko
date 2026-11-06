import { Head, Link } from '@inertiajs/react';
import { PackageSearch } from 'lucide-react';
import { CatalogFilters } from '@/components/shop/catalog-filters';
import { ProductCard } from '@/components/shop/product-card';
import { ProductCardSkeleton } from '@/components/shop/product-card-skeleton';
import { ShopEmpty } from '@/components/shop/shop-empty';
import { Button } from '@/components/ui/button';
import {
    Pagination,
    PaginationContent,
    PaginationEllipsis,
    PaginationItem,
    PaginationLink,
    PaginationNext,
    PaginationPrevious,
} from '@/components/ui/pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTableQuery } from '@/hooks/use-table-query';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import catalog from '@/routes/shop/catalog';
import type {
    CatalogCategoryHeading,
    CatalogFilters as CatalogFilterValues,
    CatalogOptions,
    Paginated,
    ShopProductCard,
} from '@/types';

type Props = {
    products: Paginated<ShopProductCard>;
    filters: CatalogFilterValues;
    /** Set when the shopper entered through `/kategori/{slug}`. */
    category: CatalogCategoryHeading | null;
    options: CatalogOptions;
};

/**
 * The catalogue grid (P1-E5-T3, T4, T5, T7).
 *
 * One screen for browsing, for a category, and for search results, because
 * they differ only in which filter arrives pre-applied — a shopper who
 * searches inside a category expects both to hold, and that only works if
 * there is one grid.
 *
 * Filters, sort and page live in the URL through `useTableQuery` (ADR-013), so
 * a narrowed catalogue can be bookmarked, shared and walked back with the
 * browser's back button.
 *
 * The grid is the company's catalogue at the company's prices (ADR-031). The
 * branch in the header's picker decides only the stock badge on each card and
 * the "tersedia di cabang ini" filter.
 */
export default function ShopCatalog({
    products,
    filters,
    category,
    options,
}: Props) {
    // Only the grid and the normalised filters are re-resolved when a filter
    // changes; `shop` is a shared prop and persists across the partial reload,
    // so the header does not flicker. Sorting is a single `sort` value here
    // rather than the hook's column+direction pair, so `table.sort` is unused.
    const table = useTableQuery({ only: ['products', 'filters'] });

    const title = category ? category.name : 'Semua produk';
    const heading = filters.search
        ? `Hasil pencarian "${filters.search}"`
        : title;

    return (
        <>
            <Head title={heading} />

            <div className="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-8">
                <CatalogHeading
                    heading={heading}
                    category={category}
                    total={products.total}
                />

                <div className="grid gap-6 lg:grid-cols-[16rem_1fr]">
                    <CatalogFilters
                        filters={filters}
                        options={options}
                        onChange={table.setFilters}
                        onReset={table.reset}
                        isFiltered={table.isFiltered}
                        categoryIsFixed={category !== null}
                    />

                    <div className="flex min-w-0 flex-col gap-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <p className="text-sm text-muted-foreground">
                                {products.total === 0
                                    ? 'Tidak ada produk'
                                    : `${formatNumber(products.total)} produk`}
                            </p>

                            <SortSelect
                                value={filters.sort}
                                options={options.sorts}
                                onChange={(sort) => table.setFilters({ sort })}
                            />
                        </div>

                        {table.isLoading ? (
                            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4">
                                <ProductCardSkeleton count={8} />
                            </div>
                        ) : products.data.length === 0 ? (
                            <ShopEmpty
                                icon={PackageSearch}
                                title={
                                    table.isFiltered
                                        ? 'Tidak ada produk yang cocok'
                                        : 'Katalog masih kosong'
                                }
                                description={
                                    table.isFiltered
                                        ? 'Ubah atau hapus filter untuk melihat produk lain. Filter "tersedia di cabang ini" membaca stok cabang yang kamu pilih di header.'
                                        : 'Belum ada produk yang terbit di katalog.'
                                }
                                action={
                                    table.isFiltered ? (
                                        <Button
                                            variant="outline"
                                            onClick={table.reset}
                                        >
                                            Reset filter
                                        </Button>
                                    ) : (
                                        <Button variant="outline" asChild>
                                            <Link href={catalog.index()}>
                                                Lihat semua produk
                                            </Link>
                                        </Button>
                                    )
                                }
                            />
                        ) : (
                            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4">
                                {products.data.map((product) => (
                                    <ProductCard
                                        key={product.id}
                                        product={product}
                                    />
                                ))}
                            </div>
                        )}

                        {products.last_page > 1 && (
                            <CatalogPagination
                                currentPage={products.current_page}
                                lastPage={products.last_page}
                                pageUrl={table.pageUrl}
                                goToPage={table.goToPage}
                            />
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

function CatalogHeading({
    heading,
    category,
    total,
}: {
    heading: string;
    category: CatalogCategoryHeading | null;
    total: number;
}) {
    return (
        <header className="flex flex-col gap-2">
            {category && category.path.length > 0 && (
                <nav aria-label="Jejak kategori">
                    <ol className="flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
                        {category.path.map((ancestor) => (
                            <li
                                key={ancestor.slug}
                                className="flex items-center gap-1"
                            >
                                <Link
                                    href={catalog.category(ancestor.slug)}
                                    className="hover:text-foreground"
                                >
                                    {ancestor.name}
                                </Link>
                                <span aria-hidden>/</span>
                            </li>
                        ))}
                        <li className="text-foreground">{category.name}</li>
                    </ol>
                </nav>
            )}

            <h1 className="text-2xl font-semibold tracking-tight">{heading}</h1>

            {category?.description && (
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {category.description}
                </p>
            )}

            <p className="sr-only" aria-live="polite">
                {formatNumber(total)} produk ditemukan
            </p>
        </header>
    );
}

function SortSelect({
    value,
    options,
    onChange,
}: {
    value: string;
    options: CatalogOptions['sorts'];
    onChange: (value: string) => void;
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger aria-label="Urutkan" className="w-48">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/**
 * Page numbers, drawn the way the panels' `DataTable` draws them: real links
 * so a page can be opened in a new tab, intercepted for an Inertia visit when
 * clicked normally.
 */
function CatalogPagination({
    currentPage,
    lastPage,
    pageUrl,
    goToPage,
}: {
    currentPage: number;
    lastPage: number;
    pageUrl: (page: number) => string;
    goToPage: (page: number) => void;
}) {
    const pages: Array<number | 'gap'> = [];

    for (let page = 1; page <= lastPage; page++) {
        const isEdge = page === 1 || page === lastPage;
        const isNearCurrent = Math.abs(page - currentPage) <= 1;

        if (isEdge || isNearCurrent) {
            pages.push(page);
        } else if (pages.at(-1) !== 'gap') {
            pages.push('gap');
        }
    }

    const handle =
        (page: number) => (event: { preventDefault: () => void }) => {
            event.preventDefault();
            goToPage(page);
        };

    return (
        <Pagination className="justify-center pt-2">
            <PaginationContent>
                <PaginationItem>
                    <PaginationPrevious
                        href={pageUrl(currentPage - 1)}
                        onClick={handle(currentPage - 1)}
                        aria-disabled={currentPage === 1}
                        className={cn(
                            currentPage === 1 &&
                                'pointer-events-none opacity-50',
                        )}
                    />
                </PaginationItem>

                {pages.map((page, index) => (
                    <PaginationItem
                        key={page === 'gap' ? `gap-${index}` : page}
                    >
                        {page === 'gap' ? (
                            <PaginationEllipsis />
                        ) : (
                            <PaginationLink
                                href={pageUrl(page)}
                                onClick={handle(page)}
                                isActive={page === currentPage}
                                className="tabular-nums"
                            >
                                {page}
                            </PaginationLink>
                        )}
                    </PaginationItem>
                ))}

                <PaginationItem>
                    <PaginationNext
                        href={pageUrl(currentPage + 1)}
                        onClick={handle(currentPage + 1)}
                        aria-disabled={currentPage === lastPage}
                        className={cn(
                            currentPage === lastPage &&
                                'pointer-events-none opacity-50',
                        )}
                    />
                </PaginationItem>
            </PaginationContent>
        </Pagination>
    );
}
