import { Head, Link } from '@inertiajs/react';
import { Eye, UserRound } from 'lucide-react';
import AdminLayout from '@/layouts/admin-layout';
import Heading from '@/components/heading';
import { DataTable } from '@/components/panel/data-table';
import { FilterBar } from '@/components/panel/filter-bar';
import { StatusBadge } from '@/components/panel/status-badge';
import { useTableQuery } from '@/hooks/use-table-query';
import { formatDateShort, formatRelative } from '@/lib/format';
import { index as customersIndex } from '@/routes/admin/customers';
import { show as customerShow } from '@/routes/admin/customers';
import type { DataTableColumn, FilterField, Paginated } from '@/types';

type CustomerItem = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    is_active: boolean;
    last_login_at: string | null;
    created_at: string | null;
};

type CustomerFilters = {
    search: string;
    status: string;
};

type CustomersIndexProps = {
    customers: Paginated<CustomerItem>;
    filters: CustomerFilters;
};

const STATUS_OPTIONS = [
    { value: 'active', label: 'Aktif' },
    { value: 'inactive', label: 'Nonaktif' },
];

const FILTER_FIELDS: FilterField[] = [
    { key: 'search', type: 'search', placeholder: 'Cari nama, email, telepon…' },
    { key: 'status', type: 'select', label: 'Status', options: STATUS_OPTIONS },
];

export default function CustomersIndex({ customers, filters }: CustomersIndexProps) {
    const { query, setFilter } = useTableQuery(filters);

    const columns: DataTableColumn<CustomerItem>[] = [
        {
            key: 'name',
            header: 'Pelanggan',
            render: (row) => (
                <div className="flex flex-col">
                    <span className="font-medium">{row.name}</span>
                    <span className="text-xs text-muted-foreground">{row.email}</span>
                </div>
            ),
        },
        {
            key: 'phone',
            header: 'Telepon',
            render: (row) => row.phone ?? <span className="text-muted-foreground">–</span>,
        },
        {
            key: 'is_active',
            header: 'Status',
            render: (row) => (
                <StatusBadge
                    variant={row.is_active ? 'success' : 'danger'}
                    label={row.is_active ? 'Aktif' : 'Nonaktif'}
                />
            ),
        },
        {
            key: 'last_login_at',
            header: 'Login terakhir',
            render: (row) =>
                row.last_login_at ? (
                    <span title={formatDateShort(row.last_login_at)}>{formatRelative(row.last_login_at)}</span>
                ) : (
                    <span className="text-muted-foreground">Belum pernah</span>
                ),
        },
        {
            key: 'created_at',
            header: 'Bergabung',
            render: (row) => (row.created_at ? formatDateShort(row.created_at) : '–'),
        },
        {
            key: 'actions',
            header: '',
            className: 'w-10',
            render: (row) => (
                <Link href={customerShow(row.id).url} className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground">
                    <Eye className="size-3.5" />
                    Detail
                </Link>
            ),
        },
    ];

    return (
        <AdminLayout
            breadcrumbs={[
                { title: 'Dashboard', href: '/admin' },
                { title: 'Pelanggan', href: customersIndex().url },
            ]}
        >
            <Head title="Pelanggan" />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex items-center gap-3">
                    <UserRound className="size-6 shrink-0 text-muted-foreground" />
                    <Heading title="Pelanggan" description="Daftar akun pelanggan yang terdaftar." />
                </div>

                <FilterBar fields={FILTER_FIELDS} values={query} onChange={setFilter} />

                <DataTable
                    columns={columns}
                    rows={customers.data}
                    paginator={customers}
                    emptyMessage="Belum ada pelanggan."
                />
            </div>
        </AdminLayout>
    );
}
