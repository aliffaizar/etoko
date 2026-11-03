import { ArrowDown, ArrowUp, ArrowUpDown, Inbox } from 'lucide-react';
import type { MouseEvent, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    Pagination,
    PaginationContent,
    PaginationEllipsis,
    PaginationItem,
    PaginationLink,
    PaginationNext,
    PaginationPrevious,
} from '@/components/ui/pagination';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    DataTableAlign,
    DataTableColumn,
    DataTableEmptyState,
    PaginatorMeta,
    TableSort,
} from '@/types/data-table';

const ALIGNMENT: Record<DataTableAlign, string> = {
    left: 'text-left',
    center: 'text-center',
    right: 'text-right',
};

export type DataTableProps<TRow> = {
    columns: DataTableColumn<TRow>[];
    rows: TRow[];
    /** Stable identity of a row, used as the React key. */
    rowKey: (row: TRow) => string | number;
    /** Paginator meta from the server; omit for a listing that fits one page. */
    paginator?: PaginatorMeta;
    sort?: TableSort;
    /** Called with a column's `sortKey` when its header is clicked. */
    onSortChange?: (column: string) => void;
    goToPage?: (page: number) => void;
    /** Builds a real href per page so links stay openable in a new tab. */
    pageUrl?: (page: number) => string;
    isLoading?: boolean;
    empty?: DataTableEmptyState;
    /** Plural noun for the result summary, e.g. "pengguna". */
    rowLabel?: string;
    rowClassName?: (row: TRow) => string | undefined;
};

/**
 * The panel listing table: sortable headers, a result summary, pagination and
 * an empty state, driven entirely by props.
 *
 * It holds no state of its own and knows nothing about the entity on show, so
 * every panel (`/admin`, `/branch`, `/pos`) reuses it. Pair it with
 * `useTableQuery` for the URL plumbing and `FilterBar` for the controls.
 */
export function DataTable<TRow>({
    columns,
    rows,
    rowKey,
    paginator,
    sort,
    onSortChange,
    goToPage,
    pageUrl,
    isLoading = false,
    empty,
    rowLabel = 'baris',
    rowClassName,
}: DataTableProps<TRow>) {
    const columnCount = columns.length;
    const showSkeleton = isLoading && rows.length === 0;

    return (
        <div className="flex flex-col gap-4">
            <div
                className={cn(
                    'overflow-hidden rounded-lg border transition-opacity',
                    isLoading && rows.length > 0 && 'opacity-60',
                )}
                aria-busy={isLoading}
            >
                <Table>
                    <TableHeader className="bg-muted/50">
                        <TableRow>
                            {columns.map((column) => (
                                <TableHead
                                    key={column.key}
                                    className={cn(
                                        ALIGNMENT[column.align ?? 'left'],
                                        column.hideOnMobile &&
                                            'hidden sm:table-cell',
                                        column.headerClassName,
                                    )}
                                >
                                    {column.srOnlyHeader ? (
                                        <span className="sr-only">
                                            {column.header}
                                        </span>
                                    ) : column.sortKey && onSortChange ? (
                                        <SortButton
                                            label={column.header}
                                            ariaLabel={
                                                typeof column.header ===
                                                'string'
                                                    ? column.header
                                                    : undefined
                                            }
                                            align={column.align ?? 'left'}
                                            isSorted={
                                                sort?.column === column.sortKey
                                            }
                                            direction={sort?.direction ?? 'asc'}
                                            onClick={() =>
                                                onSortChange(
                                                    column.sortKey as string,
                                                )
                                            }
                                        />
                                    ) : (
                                        column.header
                                    )}
                                </TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>

                    <TableBody>
                        {showSkeleton && (
                            <DataTableSkeletonRows columnCount={columnCount} />
                        )}

                        {!showSkeleton && rows.length === 0 && (
                            <TableRow className="hover:bg-transparent">
                                <TableCell
                                    colSpan={columnCount}
                                    className="p-0 whitespace-normal"
                                >
                                    <DataTableEmpty empty={empty} />
                                </TableCell>
                            </TableRow>
                        )}

                        {rows.map((row) => (
                            <TableRow
                                key={rowKey(row)}
                                className={rowClassName?.(row)}
                            >
                                {columns.map((column) => (
                                    <TableCell
                                        key={column.key}
                                        className={cn(
                                            ALIGNMENT[column.align ?? 'left'],
                                            column.hideOnMobile &&
                                                'hidden sm:table-cell',
                                            column.cellClassName,
                                        )}
                                    >
                                        {column.cell(row)}
                                    </TableCell>
                                ))}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>

            {paginator && rows.length > 0 && (
                <div className="flex flex-col items-center justify-between gap-3 sm:flex-row">
                    <p className="text-sm text-muted-foreground">
                        Menampilkan{' '}
                        <span className="font-medium text-foreground tabular-nums">
                            {formatNumber(paginator.from ?? 0)}–
                            {formatNumber(paginator.to ?? 0)}
                        </span>{' '}
                        dari{' '}
                        <span className="font-medium text-foreground tabular-nums">
                            {formatNumber(paginator.total)}
                        </span>{' '}
                        {rowLabel}
                    </p>

                    {paginator.last_page > 1 && (
                        <DataTablePagination
                            paginator={paginator}
                            goToPage={goToPage}
                            pageUrl={pageUrl}
                        />
                    )}
                </div>
            )}
        </div>
    );
}

function SortButton({
    label,
    ariaLabel,
    align,
    isSorted,
    direction,
    onClick,
}: {
    label: ReactNode;
    /** Plain-text column name for the accessible label, when there is one. */
    ariaLabel?: string;
    align: DataTableAlign;
    isSorted: boolean;
    direction: 'asc' | 'desc';
    onClick: () => void;
}) {
    const Icon = !isSorted
        ? ArrowUpDown
        : direction === 'asc'
          ? ArrowUp
          : ArrowDown;

    return (
        <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={onClick}
            aria-label={
                ariaLabel ? `Urutkan menurut ${ariaLabel}` : 'Urutkan kolom ini'
            }
            className={cn(
                '-ml-3 h-8 font-medium data-[sorted=true]:text-foreground',
                align === 'right' && '-mr-3 -ml-0',
                !isSorted && 'text-muted-foreground',
            )}
            data-sorted={isSorted}
        >
            {label}
            <Icon aria-hidden />
        </Button>
    );
}

function DataTableSkeletonRows({ columnCount }: { columnCount: number }) {
    return (
        <>
            {Array.from({ length: 5 }).map((_, rowIndex) => (
                <TableRow key={rowIndex} className="hover:bg-transparent">
                    {Array.from({ length: columnCount }).map(
                        (__, cellIndex) => (
                            <TableCell key={cellIndex}>
                                <Skeleton className="h-5 w-full animate-pulse" />
                            </TableCell>
                        ),
                    )}
                </TableRow>
            ))}
        </>
    );
}

function DataTableEmpty({ empty }: { empty?: DataTableEmptyState }) {
    const Icon = empty?.icon ?? Inbox;

    return (
        <Empty className="border-0 py-12">
            <EmptyHeader>
                <EmptyMedia variant="icon">
                    <Icon aria-hidden />
                </EmptyMedia>
                <EmptyTitle>{empty?.title ?? 'Belum ada data'}</EmptyTitle>
                {empty?.description && (
                    <EmptyDescription>{empty.description}</EmptyDescription>
                )}
            </EmptyHeader>
            {empty?.action}
        </Empty>
    );
}

/**
 * Page numbers with ellipsis gaps, always showing the first, last and the
 * pages immediately around the current one.
 */
function DataTablePagination({
    paginator,
    goToPage,
    pageUrl,
}: {
    paginator: PaginatorMeta;
    goToPage?: (page: number) => void;
    pageUrl?: (page: number) => string;
}) {
    const { current_page: current, last_page: last } = paginator;

    const pages: Array<number | 'gap'> = [];

    for (let page = 1; page <= last; page++) {
        const isEdge = page === 1 || page === last;
        const isNearCurrent = Math.abs(page - current) <= 1;

        if (isEdge || isNearCurrent) {
            pages.push(page);
        } else if (pages.at(-1) !== 'gap') {
            pages.push('gap');
        }
    }

    const handle =
        (page: number) =>
        (event: MouseEvent<HTMLAnchorElement>): void => {
            if (!goToPage) {
                return;
            }

            event.preventDefault();
            goToPage(page);
        };

    return (
        <Pagination className="mx-0 w-auto justify-end">
            <PaginationContent>
                <PaginationItem>
                    <PaginationPrevious
                        href={pageUrl?.(current - 1) ?? '#'}
                        onClick={handle(current - 1)}
                        aria-disabled={current === 1}
                        className={cn(
                            current === 1 && 'pointer-events-none opacity-50',
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
                                href={pageUrl?.(page) ?? '#'}
                                onClick={handle(page)}
                                isActive={page === current}
                                className="tabular-nums"
                            >
                                {page}
                            </PaginationLink>
                        )}
                    </PaginationItem>
                ))}

                <PaginationItem>
                    <PaginationNext
                        href={pageUrl?.(current + 1) ?? '#'}
                        onClick={handle(current + 1)}
                        aria-disabled={current === last}
                        className={cn(
                            current === last &&
                                'pointer-events-none opacity-50',
                        )}
                    />
                </PaginationItem>
            </PaginationContent>
        </Pagination>
    );
}
