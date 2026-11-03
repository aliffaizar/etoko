import { Head, Link } from '@inertiajs/react';
import { Pencil, ShieldCheck, ShieldX } from 'lucide-react';
import RoleController from '@/actions/App/Http/Controllers/Admin/RoleController';
import Heading from '@/components/heading';
import { DataTable } from '@/components/panel/data-table';
import { FilterBar } from '@/components/panel/filter-bar';
import { StatusBadge } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { formatNumber } from '@/lib/format';
import { index as rolesIndex } from '@/routes/admin/roles';
import type { DataTableColumn, FilterField } from '@/types';

type RoleRow = {
    id: number;
    name: string;
    label: string;
    is_editable: boolean;
    permission_count: number;
    user_count: number;
    is_central: boolean;
    requires_branch: boolean;
};

type RolesIndexProps = {
    roles: RoleRow[];
    permissionTotal: number;
};

const FILTER_FIELDS: FilterField[] = [
    {
        type: 'search',
        key: 'search',
        label: 'Cari role',
        placeholder: 'Cari role…',
    },
];

export default function RolesIndex({
    roles,
    permissionTotal,
}: RolesIndexProps) {
    // There are only a handful of roles, so the search filters client side and
    // the listing needs no paginator — DataTable works either way.
    const table = useTableQuery();
    const search = (table.values.search ?? '').toLowerCase();

    const visibleRoles =
        search === ''
            ? roles
            : roles.filter(
                  (role) =>
                      role.label.toLowerCase().includes(search) ||
                      role.name.toLowerCase().includes(search),
              );

    const columns: DataTableColumn<RoleRow>[] = [
        {
            key: 'label',
            header: 'Role',
            cell: (role) => (
                <div className="min-w-0">
                    <p className="truncate font-medium">{role.label}</p>
                    <p className="truncate font-mono text-xs text-muted-foreground">
                        {role.name}
                    </p>
                </div>
            ),
        },
        {
            key: 'scope',
            header: 'Cakupan data',
            hideOnMobile: true,
            cell: (role) =>
                role.is_central ? (
                    <StatusBadge label="Semua cabang" tone="info" />
                ) : role.requires_branch ? (
                    <StatusBadge label="Satu cabang" tone="neutral" />
                ) : (
                    <StatusBadge label="Storefront" tone="neutral" />
                ),
        },
        {
            key: 'permission_count',
            header: 'Permission',
            align: 'right',
            cell: (role) => (
                <span className="text-sm tabular-nums">
                    {role.is_editable ? (
                        <>
                            {formatNumber(role.permission_count)}
                            <span className="text-muted-foreground">
                                {' / '}
                                {formatNumber(permissionTotal)}
                            </span>
                        </>
                    ) : (
                        <span className="text-muted-foreground">Semua</span>
                    )}
                </span>
            ),
        },
        {
            key: 'user_count',
            header: 'Pengguna',
            align: 'right',
            hideOnMobile: true,
            cell: (role) => (
                <span className="text-sm tabular-nums">
                    {formatNumber(role.user_count)}
                </span>
            ),
        },
        {
            key: 'actions',
            header: 'Aksi',
            srOnlyHeader: true,
            align: 'right',
            cell: (role) =>
                role.is_editable ? (
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={RoleController.edit(role.id)}>
                            <Pencil aria-hidden />
                            Ubah permission
                        </Link>
                    </Button>
                ) : (
                    <span className="text-xs text-muted-foreground">
                        Tidak dapat diubah
                    </span>
                ),
        },
    ];

    return (
        <>
            <Head title="Role & Permission" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Role & Permission"
                    description="Tentukan hak akses setiap role. Perubahan langsung berlaku untuk semua pengguna dengan role tersebut."
                />

                <div className="flex items-start gap-3 rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                    <ShieldCheck
                        aria-hidden
                        className="mt-0.5 size-4 shrink-0"
                    />
                    <p>
                        Role <strong>Super Admin</strong> selalu memiliki
                        seluruh {formatNumber(permissionTotal)} permission dan
                        tidak dapat diubah, agar sistem tidak pernah kehilangan
                        pemilik akses penuh.
                    </p>
                </div>

                <FilterBar
                    fields={FILTER_FIELDS}
                    values={table.values}
                    onChange={table.setFilters}
                    onReset={table.reset}
                    isFiltered={table.isFiltered}
                />

                <DataTable
                    columns={columns}
                    rows={visibleRoles}
                    rowKey={(role) => role.id}
                    rowLabel="role"
                    isLoading={table.isLoading}
                    empty={{
                        icon: ShieldX,
                        title: 'Tidak ada role yang cocok',
                        description:
                            'Ubah kata kunci pencarian untuk melihat role lain.',
                        action: (
                            <Button variant="outline" onClick={table.reset}>
                                Reset filter
                            </Button>
                        ),
                    }}
                />
            </div>
        </>
    );
}

RolesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Role & Permission',
            href: rolesIndex(),
        },
    ],
};
