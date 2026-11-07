import { Head, Link } from '@inertiajs/react';
import { Eye, UserRound } from 'lucide-react';
import Heading from '@/components/heading';
import { DataTable } from '@/components/panel/data-table';
import { FilterBar } from '@/components/panel/filter-bar';
import { StatusBadge } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { formatDateShort, formatRelative } from '@/lib/format';
import { show as customerShow } from '@/routes/admin/customers';
import type { DataTableColumn, FilterField, Paginated } from '@/types';

type CustomerItem = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    is_active: boolean;
    order_count: number;
    last_login_at: string | null;
    created_at: string | null;
};

type CustomersIndexProps = {
    customers: Paginated<CustomerItem>;
    options: { statuses: { value: string; label: string }[] };
};

export default function CustomersIndex({
    customers,
    options,
}: CustomersIndexProps) {
    const table = useTableQuery({
        only: ['customers', 'filters'],
        defaultSort: 'created_at',
        defaultDirection: 'desc',
    });

    const filterFields: FilterField[] = [
        {
            type: 'search',
            key: 'search',
            label: 'Cari pelanggan',
            placeholder: 'Cari nama, email, atau telepon…',
        },
        {
            type: 'select',
            key: 'status',
            label: 'Status',
            options: options.statuses,
            allLabel: 'Semua status',
            className: 'sm:w-44',
        },
    ];

    const columns: DataTableColumn<CustomerItem>[] = [
        {
            key: 'name',
            header: 'Pelanggan',
            sortKey: 'name',
            cell: (customer) => (
                <div className="min-w-0">
                    <p className="truncate font-medium">{customer.name}</p>
                    <p className="truncate text-xs text-muted-foreground">
                        {customer.email}
                    </p>
                </div>
            ),
        },
        {
            key: 'phone',
            header: 'Telepon',
            hideOnMobile: true,
            cell: (customer) => (
                <span className="text-sm">{customer.phone ?? '-'}</span>
            ),
        },
        {
            key: 'is_active',
            header: 'Status',
            cell: (customer) => (
                <StatusBadge
                    label={customer.is_active ? 'Aktif' : 'Nonaktif'}
                    tone={customer.is_active ? 'success' : 'danger'}
                />
            ),
        },
        {
            key: 'order_count',
            header: 'Pesanan',
            align: 'right',
            cell: (customer) => (
                <span className="text-sm tabular-nums">
                    {customer.order_count}
                </span>
            ),
        },
        {
            key: 'last_login_at',
            header: 'Login terakhir',
            hideOnMobile: true,
            cell: (customer) =>
                customer.last_login_at ? (
                    <span
                        className="text-sm"
                        title={formatDateShort(customer.last_login_at)}
                    >
                        {formatRelative(customer.last_login_at)}
                    </span>
                ) : (
                    <span className="text-sm text-muted-foreground">
                        Belum pernah
                    </span>
                ),
        },
        {
            key: 'created_at',
            header: 'Bergabung',
            sortKey: 'created_at',
            hideOnMobile: true,
            cell: (customer) => (
                <span className="text-sm">
                    {formatDateShort(customer.created_at)}
                </span>
            ),
        },
        {
            key: 'actions',
            header: 'Aksi',
            srOnlyHeader: true,
            align: 'right',
            cell: (customer) => (
                <Button variant="ghost" size="sm" asChild>
                    <Link href={customerShow(customer.id).url}>
                        <Eye aria-hidden />
                        Detail
                    </Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title="Pelanggan" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Pelanggan"
                    description="Daftar akun pelanggan beserta riwayat pesanannya."
                />

                <FilterBar
                    fields={filterFields}
                    values={table.values}
                    onChange={table.setFilters}
                    onReset={table.reset}
                    isFiltered={table.isFiltered}
                />

                <DataTable
                    columns={columns}
                    rows={customers.data}
                    rowKey={(customer) => customer.id}
                    paginator={customers}
                    rowLabel="pelanggan"
                    sort={table.sort}
                    onSortChange={table.toggleSort}
                    goToPage={table.goToPage}
                    pageUrl={table.pageUrl}
                    isLoading={table.isLoading}
                    empty={{
                        icon: UserRound,
                        title: table.isFiltered
                            ? 'Tidak ada pelanggan yang cocok'
                            : 'Belum ada pelanggan',
                        description: table.isFiltered
                            ? 'Ubah atau hapus filter untuk melihat pelanggan lain.'
                            : 'Pelanggan muncul di sini setelah mendaftar di toko.',
                        action: table.isFiltered ? (
                            <Button variant="outline" onClick={table.reset}>
                                Reset filter
                            </Button>
                        ) : undefined,
                    }}
                />
            </div>
        </>
    );
}
