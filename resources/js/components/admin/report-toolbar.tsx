import { Link } from '@inertiajs/react';
import { FileSpreadsheet, FileText } from 'lucide-react';
import { useId } from 'react';
import { FilterBar } from '@/components/panel/filter-bar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useAuthorization } from '@/hooks/use-authorization';
import type { UseTableQueryReturn } from '@/hooks/use-table-query';
import { cn } from '@/lib/utils';
import reports, { exportMethod as exportReport } from '@/routes/admin/reports';
import type { FilterField, ReportFilters, ReportPageProps } from '@/types';

export type ReportKey =
    | 'sales'
    | 'transactions'
    | 'stock'
    | 'stock-card'
    | 'products'
    | 'customers'
    | 'branches';

/**
 * Every report, in menu order, with the permission that opens it — the same
 * list as `ReportCatalog::REPORTS` on the server.
 */
export const REPORT_LINKS: {
    key: ReportKey;
    title: string;
    permission: string;
    href: () => { url: string };
}[] = [
    {
        key: 'sales',
        title: 'Penjualan',
        permission: 'reports.sales.view',
        href: () => reports.sales(),
    },
    {
        key: 'transactions',
        title: 'Transaksi',
        permission: 'reports.sales.view',
        href: () => reports.transactions(),
    },
    {
        key: 'products',
        title: 'Produk Terlaris',
        permission: 'reports.product.view',
        href: () => reports.products(),
    },
    {
        key: 'customers',
        title: 'Pelanggan',
        permission: 'reports.customer.view',
        href: () => reports.customers(),
    },
    {
        key: 'stock',
        title: 'Posisi Stok',
        permission: 'reports.stock.view',
        href: () => reports.stock(),
    },
    {
        key: 'stock-card',
        title: 'Kartu Stok',
        permission: 'reports.stock.view',
        href: () => reports.stockCard(),
    },
    {
        key: 'branches',
        title: 'Performa Cabang',
        permission: 'reports.branch.view',
        href: () => reports.branches(),
    },
];

const GROUP_OPTIONS = [
    { value: 'week', label: 'Mingguan' },
    { value: 'month', label: 'Bulanan' },
];

/**
 * The tabs across the top of every report, limited to the ones the actor
 * may open.
 */
export function ReportTabs({ current }: { current: ReportKey }) {
    const { can } = useAuthorization();
    const links = REPORT_LINKS.filter((link) => can(link.permission));

    return (
        <nav
            aria-label="Jenis laporan"
            className="-mx-4 flex gap-1 overflow-x-auto px-4 pb-1"
        >
            {links.map((link) => (
                <Button
                    key={link.key}
                    asChild
                    size="sm"
                    variant={link.key === current ? 'secondary' : 'ghost'}
                    className="shrink-0"
                >
                    <Link
                        href={link.href().url}
                        aria-current={link.key === current ? 'page' : undefined}
                        prefetch
                    >
                        {link.title}
                    </Link>
                </Button>
            ))}
        </nav>
    );
}

/**
 * The report toolbar: period, grouping, branch and category, plus any filters
 * of the report's own, and the export buttons (P2-E7-T4, P2-E7-T9).
 *
 * Every value lives in the URL through `useTableQuery`, so a report can be
 * bookmarked and sent on, and the export links carry exactly the filters on
 * screen — the file is the screen's question asked again.
 */
export function ReportToolbar({
    reportKey,
    query,
    filters,
    options,
    can,
    withPeriod = true,
    withGroup = false,
    withCategory = true,
    extraFields = [],
}: {
    reportKey: ReportKey;
    query: UseTableQueryReturn;
    filters: ReportFilters;
    options: ReportPageProps<unknown>['options'];
    can: ReportPageProps<unknown>['can'];
    withPeriod?: boolean;
    withGroup?: boolean;
    withCategory?: boolean;
    extraFields?: FilterField[];
}) {
    const fields: FilterField[] = [...extraFields];

    if (withGroup) {
        fields.push({
            type: 'select',
            key: 'group',
            label: 'Pengelompokan',
            allLabel: 'Harian',
            options: GROUP_OPTIONS,
            className: 'sm:w-36',
        });
    }

    if (options.branches.length > 0) {
        fields.push({
            type: 'select',
            key: 'branch',
            label: 'Cabang',
            allLabel: 'Semua cabang',
            options: options.branches,
        });
    }

    if (withCategory) {
        fields.push({
            type: 'select',
            key: 'category',
            label: 'Kategori',
            allLabel: 'Semua kategori',
            options: options.categories,
        });
    }

    const exportQuery = Object.fromEntries(
        Object.entries(query.values).filter(
            ([key, value]) => key !== 'page' && value !== '',
        ),
    );

    return (
        <div className="flex flex-col gap-3">
            {withPeriod && (
                <PeriodPicker
                    from={filters.from}
                    until={filters.until}
                    onChange={(next) => query.setFilters(next)}
                />
            )}

            <FilterBar
                fields={fields}
                values={query.values}
                onChange={query.setFilters}
                onReset={query.reset}
                isFiltered={query.isFiltered}
                actions={
                    can.export ? (
                        <>
                            <Button variant="outline" size="sm" asChild>
                                <a
                                    href={exportReport.url(reportKey, {
                                        query: {
                                            ...exportQuery,
                                            format: 'xlsx',
                                        },
                                    })}
                                >
                                    <FileSpreadsheet data-icon="inline-start" />
                                    Excel
                                </a>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <a
                                    href={exportReport.url(reportKey, {
                                        query: {
                                            ...exportQuery,
                                            format: 'pdf',
                                        },
                                    })}
                                >
                                    <FileText data-icon="inline-start" />
                                    PDF
                                </a>
                            </Button>
                        </>
                    ) : undefined
                }
            />
        </div>
    );
}

/** Quick ranges, in days counted back from today (today included). */
const QUICK_RANGES = [
    { label: '7 hari', days: 7 },
    { label: '30 hari', days: 30 },
    { label: '90 hari', days: 90 },
];

function toDateInput(date: Date): string {
    const offset = date.getTimezoneOffset() * 60_000;

    return new Date(date.getTime() - offset).toISOString().slice(0, 10);
}

function PeriodPicker({
    from,
    until,
    onChange,
}: {
    from: string;
    until: string;
    onChange: (next: { from: string; until: string }) => void;
}) {
    const fromId = useId();
    const untilId = useId();
    const today = new Date();
    const monthStart = toDateInput(
        new Date(today.getFullYear(), today.getMonth(), 1),
    );
    const todayValue = toDateInput(today);

    const quick = [
        ...QUICK_RANGES.map((range) => {
            const start = new Date(today);
            start.setDate(start.getDate() - (range.days - 1));

            return {
                label: range.label,
                from: toDateInput(start),
                until: todayValue,
            };
        }),
        { label: 'Bulan ini', from: monthStart, until: todayValue },
    ];

    return (
        <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
            <div className="grid gap-1.5">
                <Label
                    htmlFor={fromId}
                    className="text-xs text-muted-foreground"
                >
                    Dari
                </Label>
                <Input
                    id={fromId}
                    type="date"
                    value={from}
                    max={until}
                    onChange={(event) =>
                        event.target.value &&
                        onChange({ from: event.target.value, until })
                    }
                    className="w-full sm:w-40"
                />
            </div>
            <div className="grid gap-1.5">
                <Label
                    htmlFor={untilId}
                    className="text-xs text-muted-foreground"
                >
                    Sampai
                </Label>
                <Input
                    id={untilId}
                    type="date"
                    value={until}
                    min={from}
                    onChange={(event) =>
                        event.target.value &&
                        onChange({ from, until: event.target.value })
                    }
                    className="w-full sm:w-40"
                />
            </div>
            <div className="flex flex-wrap gap-1">
                {quick.map((range) => {
                    const isActive =
                        range.from === from && range.until === until;

                    return (
                        <Button
                            key={range.label}
                            type="button"
                            size="sm"
                            variant={isActive ? 'secondary' : 'ghost'}
                            className={cn(isActive && 'pointer-events-none')}
                            onClick={() =>
                                onChange({
                                    from: range.from,
                                    until: range.until,
                                })
                            }
                        >
                            {range.label}
                        </Button>
                    );
                })}
            </div>
        </div>
    );
}
