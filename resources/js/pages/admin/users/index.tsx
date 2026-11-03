import { Head, Link, router } from '@inertiajs/react';
import {
    MoreHorizontal,
    Pencil,
    Plus,
    Trash2,
    UserCheck,
    UserRound,
    UserX,
} from 'lucide-react';
import { useState } from 'react';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import Heading from '@/components/heading';
import { DataTable } from '@/components/panel/data-table';
import { FilterBar } from '@/components/panel/filter-bar';
import { StatusBadge } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAuthorization } from '@/hooks/use-authorization';
import { useTableQuery } from '@/hooks/use-table-query';
import { formatDateShort, formatRelative } from '@/lib/format';
import { index as usersIndex } from '@/routes/admin/users';
import type {
    DataTableColumn,
    FilterField,
    Paginated,
    StaffFilterOptions,
    StaffFilters,
    StaffListItem,
} from '@/types';

type UsersIndexProps = {
    users: Paginated<StaffListItem>;
    filters: StaffFilters;
    options: StaffFilterOptions;
};

const STATUS_OPTIONS = [
    { value: 'active', label: 'Aktif' },
    { value: 'inactive', label: 'Nonaktif' },
];

export default function UsersIndex({ users, options }: UsersIndexProps) {
    const { can } = useAuthorization();
    const [pendingDelete, setPendingDelete] = useState<StaffListItem | null>(
        null,
    );

    const table = useTableQuery({
        only: ['users', 'filters'],
        defaultSort: 'name',
    });

    const filterFields: FilterField[] = [
        {
            type: 'search',
            key: 'search',
            label: 'Cari pengguna',
            placeholder: 'Cari nama, email, atau telepon…',
        },
        {
            type: 'select',
            key: 'role',
            label: 'Role',
            options: options.roles,
            allLabel: 'Semua role',
        },
        ...(options.showBranchFilter
            ? ([
                  {
                      type: 'select',
                      key: 'branch',
                      label: 'Cabang',
                      options: options.branches,
                      allLabel: 'Semua cabang',
                      className: 'sm:w-56',
                  },
              ] as FilterField[])
            : []),
        {
            type: 'select',
            key: 'status',
            label: 'Status',
            options: STATUS_OPTIONS,
            allLabel: 'Semua status',
            className: 'sm:w-40',
        },
    ];

    const columns: DataTableColumn<StaffListItem>[] = [
        {
            key: 'name',
            header: 'Nama',
            sortKey: 'name',
            cell: (user) => (
                <div className="min-w-0">
                    <p className="truncate font-medium">
                        {user.name}
                        {user.is_self && (
                            <span className="ml-2 text-xs font-normal text-muted-foreground">
                                (Anda)
                            </span>
                        )}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                        {user.email}
                    </p>
                </div>
            ),
        },
        {
            key: 'role',
            header: 'Role',
            cell: (user) => (
                <StatusBadge
                    label={user.role_label ?? 'Tanpa role'}
                    tone={user.role_label ? 'neutral' : 'warning'}
                />
            ),
        },
        {
            key: 'branch',
            header: 'Cabang',
            hideOnMobile: true,
            cell: (user) =>
                user.branch ? (
                    <span className="text-sm">
                        {user.branch.name}
                        <span className="ml-1 text-xs text-muted-foreground">
                            {user.branch.code}
                        </span>
                    </span>
                ) : (
                    <span className="text-sm text-muted-foreground">Pusat</span>
                ),
        },
        {
            key: 'phone',
            header: 'Telepon',
            hideOnMobile: true,
            cell: (user) => (
                <span className="text-sm tabular-nums">
                    {user.phone ?? '-'}
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (user) => (
                <StatusBadge
                    label={user.is_active ? 'Aktif' : 'Nonaktif'}
                    tone={user.is_active ? 'success' : 'danger'}
                />
            ),
        },
        {
            key: 'last_login_at',
            header: 'Login terakhir',
            sortKey: 'last_login_at',
            hideOnMobile: true,
            cell: (user) => (
                <span
                    className="text-sm text-muted-foreground"
                    title={formatDateShort(user.last_login_at)}
                >
                    {user.last_login_at
                        ? formatRelative(user.last_login_at)
                        : 'Belum pernah'}
                </span>
            ),
        },
        {
            key: 'actions',
            header: 'Aksi',
            srOnlyHeader: true,
            align: 'right',
            cell: (user) => (
                <RowActions
                    user={user}
                    onRequestDelete={() => setPendingDelete(user)}
                />
            ),
        },
    ];

    return (
        <>
            <Head title="Pengguna" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Pengguna"
                    description="Kelola akun staf yang dapat masuk ke panel admin, cabang, dan kasir."
                />

                <FilterBar
                    fields={filterFields}
                    values={table.values}
                    onChange={table.setFilters}
                    onReset={table.reset}
                    isFiltered={table.isFiltered}
                    actions={
                        can('users.create') && (
                            <Button asChild>
                                <Link href={UserController.create()}>
                                    <Plus aria-hidden />
                                    Tambah Pengguna
                                </Link>
                            </Button>
                        )
                    }
                />

                <DataTable
                    columns={columns}
                    rows={users.data}
                    rowKey={(user) => user.id}
                    paginator={users}
                    rowLabel="pengguna"
                    sort={table.sort}
                    onSortChange={table.toggleSort}
                    goToPage={table.goToPage}
                    pageUrl={table.pageUrl}
                    isLoading={table.isLoading}
                    empty={{
                        icon: UserRound,
                        title: table.isFiltered
                            ? 'Tidak ada pengguna yang cocok'
                            : 'Belum ada pengguna',
                        description: table.isFiltered
                            ? 'Ubah atau hapus filter untuk melihat pengguna lain.'
                            : 'Tambahkan akun staf pertama untuk mulai mengelola panel.',
                        action: table.isFiltered ? (
                            <Button variant="outline" onClick={table.reset}>
                                Reset filter
                            </Button>
                        ) : (
                            can('users.create') && (
                                <Button asChild>
                                    <Link href={UserController.create()}>
                                        <Plus aria-hidden />
                                        Tambah Pengguna
                                    </Link>
                                </Button>
                            )
                        ),
                    }}
                />
            </div>

            <DeleteUserDialog
                user={pendingDelete}
                onClose={() => setPendingDelete(null)}
            />
        </>
    );
}

function RowActions({
    user,
    onRequestDelete,
}: {
    user: StaffListItem;
    onRequestDelete: () => void;
}) {
    const hasAnyAction =
        user.can.update || user.can.toggle_active || user.can.delete;

    if (!hasAnyAction) {
        return null;
    }

    const toggleActive = (): void => {
        router.patch(
            UserController.updateStatus(user.id).url,
            { is_active: !user.is_active },
            { preserveScroll: true },
        );
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label={`Aksi untuk ${user.name}`}
                >
                    <MoreHorizontal aria-hidden />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuGroup>
                    {user.can.update && (
                        <DropdownMenuItem asChild>
                            <Link href={UserController.edit(user.id)}>
                                <Pencil aria-hidden />
                                Ubah
                            </Link>
                        </DropdownMenuItem>
                    )}

                    {user.can.toggle_active && (
                        <DropdownMenuItem onClick={toggleActive}>
                            {user.is_active ? (
                                <>
                                    <UserX aria-hidden />
                                    Nonaktifkan
                                </>
                            ) : (
                                <>
                                    <UserCheck aria-hidden />
                                    Aktifkan
                                </>
                            )}
                        </DropdownMenuItem>
                    )}
                </DropdownMenuGroup>

                {user.can.delete && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            onClick={onRequestDelete}
                        >
                            <Trash2 aria-hidden />
                            Hapus
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function DeleteUserDialog({
    user,
    onClose,
}: {
    user: StaffListItem | null;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);

    const confirm = (): void => {
        if (!user) {
            return;
        }

        router.delete(UserController.destroy(user.id).url, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                onClose();
            },
        });
    };

    return (
        <Dialog
            open={user !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Hapus pengguna?</DialogTitle>
                    <DialogDescription>
                        Akun <strong>{user?.name}</strong> tidak akan bisa masuk
                        lagi. Data historis yang dibuat akun ini tetap
                        tersimpan, dan alamat emailnya belum bisa dipakai ulang.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={onClose}
                        disabled={processing}
                    >
                        Batal
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={confirm}
                        disabled={processing}
                    >
                        Hapus pengguna
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

UsersIndex.layout = {
    breadcrumbs: [
        {
            title: 'Pengguna',
            href: usersIndex(),
        },
    ],
};
