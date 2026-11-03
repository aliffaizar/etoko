import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

/** One entry of Laravel's paginator `links` array. */
export type PaginatorLink = {
    url: string | null;
    label: string;
    active: boolean;
};

/**
 * The shape `LengthAwarePaginator` serialises to, as Inertia receives it.
 *
 * Rows are kept separate from the meta so a page can hand the meta to
 * `DataTable` without the component needing to know the row type.
 */
export type PaginatorMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

export type Paginated<TRow> = PaginatorMeta & {
    data: TRow[];
    links: PaginatorLink[];
};

export type SortDirection = 'asc' | 'desc';

export type TableSort = {
    column: string | null;
    direction: SortDirection;
};

/** Horizontal alignment of a column's header and cells. */
export type DataTableAlign = 'left' | 'center' | 'right';

export type DataTableColumn<TRow> = {
    /** Unique key of the column; also the React key of its cells. */
    key: string;
    header: ReactNode;
    /** Renders the cell for one row. */
    cell: (row: TRow) => ReactNode;
    /**
     * Server-side sort key. When set, the header becomes a sort toggle; the
     * value must be one of the sortable columns the endpoint accepts.
     */
    sortKey?: string;
    align?: DataTableAlign;
    headerClassName?: string;
    cellClassName?: string;
    /** Hidden below the `sm` breakpoint when true, to keep narrow screens readable. */
    hideOnMobile?: boolean;
    /** Screen-reader-only header, for action columns. */
    srOnlyHeader?: boolean;
};

export type DataTableEmptyState = {
    title: string;
    description?: string;
    icon?: LucideIcon;
    action?: ReactNode;
};

/** A single option of a `FilterBar` select. */
export type FilterOption = {
    value: string;
    label: string;
};

export type FilterField =
    | {
          type: 'search';
          key: string;
          placeholder?: string;
          /** Accessible label; defaults to the placeholder. */
          label?: string;
      }
    | {
          type: 'select';
          key: string;
          label: string;
          options: FilterOption[];
          /** Label of the "no filter" option, e.g. "Semua role". */
          allLabel?: string;
          className?: string;
      };
