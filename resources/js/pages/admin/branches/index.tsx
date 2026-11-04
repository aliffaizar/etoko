import { Head, Link, router } from '@inertiajs/react';
import {
    Bike,
    MoreHorizontal,
    Pencil,
    Plus,
    Star,
    Store,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import BranchController from '@/actions/App/Http/Controllers/Admin/BranchController';
import Heading from '@/components/heading';
import { DataTable } from '@/components/panel/data-table';
import { FilterBar } from '@/components/panel/filter-bar';
import { StatusBadge, type StatusTone } from '@/components/panel/status-badge';
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
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useTableQuery } from '@/hooks/use-table-query';
import { index as branchesIndex } from '@/routes/admin/branches';
import type {
    BranchListItem,
    BranchStatus,
    BranchStatusOption,
    DataTableColumn,
    FilterField,
    Paginated,
} from '@/types';

type BranchesIndexProps = {
    branches: Paginated<BranchListItem>;
    options: { statuses: BranchStatusOption[] };
    can: { create: boolean };
};

const FULFILLMENT_OPTIONS = [
    { value: 'delivery', label: 'Mendukung pengiriman' },
    { value: 'pickup', label: 'Mendukung ambil di cabang' },
];

/** How each branch status reads at a glance. */
const STATUS_TONE: Record<BranchStatus, StatusTone> = {
    active: 'success',
    inactive: 'danger',
    maintenance: 'warning',
};

export default function BranchesIndex({
    branches,
    options,
    can,
}: BranchesIndexProps) {
    const [pendingDelete, setPendingDelete] = useState<BranchListItem | null>(
        null,
    );

    const table = useTableQuery({
        only: ['branches', 'filters'],
        defaultSort: 'sort_order',
    });

    const filterFields: FilterField[] = [
        {
            type: 'search',
            key: 'search',
            label: 'Cari cabang',
            placeholder: 'Cari nama, kode, atau kota…',
        },
        {
            type: 'select',
            key: 'status',
            label: 'Status',
            options: options.statuses,
            allLabel: 'Semua status',
            className: 'sm:w-44',
        },
        {
            type: 'select',
            key: 'fulfillment',
            label: 'Pemenuhan',
            options: FULFILLMENT_OPTIONS,
            allLabel: 'Semua pemenuhan',
            className: 'sm:w-60',
        },
    ];

    const addButton = can.create && (
        <Button asChild>
            <Link href={BranchController.create()}>
                <Plus aria-hidden />
                Tambah Cabang
            </Link>
        </Button>
    );

    const columns: DataTableColumn<BranchListItem>[] = [
        {
            key: 'name',
            header: 'Cabang',
            sortKey: 'name',
            cell: (branch) => (
                <div className="min-w-0">
                    <p className="flex items-center gap-2 truncate font-medium">
                        {branch.name}
                        {branch.is_default && (
                            <span
                                className="inline-flex items-center gap-1 text-xs font-normal text-muted-foreground"
                                title="Cabang utama storefront"
                            >
                                <Star
                                    aria-hidden
                                    className="size-3.5 fill-amber-400 text-amber-500"
                                />
                                Utama
                            </span>
                        )}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                        {branch.code}
                        {branch.city_name ? ` · ${branch.city_name}` : ''}
                    </p>
                </div>
            ),
        },
        {
            key: 'province_name',
            header: 'Provinsi',
            hideOnMobile: true,
            cell: (branch) => (
                <span className="text-sm">{branch.province_name ?? '-'}</span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (branch) => (
                <StatusBadge
                    label={branch.status_label}
                    tone={STATUS_TONE[branch.status]}
                />
            ),
        },
        {
            key: 'fulfillment',
            header: 'Pemenuhan',
            hideOnMobile: true,
            cell: (branch) => (
                <div className="flex flex-wrap gap-1">
                    {branch.supports_delivery && (
                        <StatusBadge label="Kirim" tone="neutral" />
                    )}
                    {branch.supports_pickup && (
                        <StatusBadge label="Ambil" tone="neutral" />
                    )}
                    {!branch.supports_delivery && !branch.supports_pickup && (
                        <span className="text-sm text-muted-foreground">-</span>
                    )}
                </div>
            ),
        },
        {
            key: 'warehouse_count',
            header: 'Gudang',
            align: 'right',
            hideOnMobile: true,
            cell: (branch) => (
                <span className="text-sm tabular-nums">
                    {branch.warehouse_count}
                </span>
            ),
        },
        {
            key: 'staff_count',
            header: 'Staf',
            align: 'right',
            hideOnMobile: true,
            cell: (branch) => (
                <span className="text-sm tabular-nums">
                    {branch.staff_count}
                </span>
            ),
        },
        {
            key: 'actions',
            header: 'Aksi',
            srOnlyHeader: true,
            align: 'right',
            cell: (branch) => (
                <RowActions
                    branch={branch}
                    statuses={options.statuses}
                    onRequestDelete={() => setPendingDelete(branch)}
                />
            ),
        },
    ];

    return (
        <>
            <Head title="Cabang" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Cabang"
                    description="Kelola cabang, alamat, jam operasional, gudang, dan stafnya."
                />

                <FilterBar
                    fields={filterFields}
                    values={table.values}
                    onChange={table.setFilters}
                    onReset={table.reset}
                    isFiltered={table.isFiltered}
                    actions={addButton}
                />

                <DataTable
                    columns={columns}
                    rows={branches.data}
                    rowKey={(branch) => branch.id}
                    paginator={branches}
                    rowLabel="cabang"
                    sort={table.sort}
                    onSortChange={table.toggleSort}
                    goToPage={table.goToPage}
                    pageUrl={table.pageUrl}
                    isLoading={table.isLoading}
                    empty={{
                        icon: Store,
                        title: table.isFiltered
                            ? 'Tidak ada cabang yang cocok'
                            : 'Belum ada cabang',
                        description: table.isFiltered
                            ? 'Ubah atau hapus filter untuk melihat cabang lain.'
                            : 'Tambahkan cabang pertama untuk mulai berjualan.',
                        action: table.isFiltered ? (
                            <Button variant="outline" onClick={table.reset}>
                                Reset filter
                            </Button>
                        ) : (
                            addButton
                        ),
                    }}
                />
            </div>

            <DeleteBranchDialog
                branch={pendingDelete}
                onClose={() => setPendingDelete(null)}
            />
        </>
    );
}

function RowActions({
    branch,
    statuses,
    onRequestDelete,
}: {
    branch: BranchListItem;
    statuses: BranchStatusOption[];
    onRequestDelete: () => void;
}) {
    if (
        !branch.can.update &&
        !branch.can.delete &&
        !branch.delete_blocked_reason
    ) {
        return null;
    }

    const changeStatus = (status: string): void => {
        if (status === branch.status) {
            return;
        }

        router.patch(
            BranchController.updateStatus(branch.id).url,
            { status },
            { preserveScroll: true },
        );
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label={`Aksi untuk ${branch.name}`}
                >
                    <MoreHorizontal aria-hidden />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuGroup>
                    {branch.can.update && (
                        <DropdownMenuItem asChild>
                            <Link href={BranchController.edit(branch.id)}>
                                <Pencil aria-hidden />
                                Kelola cabang
                            </Link>
                        </DropdownMenuItem>
                    )}
                </DropdownMenuGroup>

                {branch.can.update && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel className="flex items-center gap-2">
                            <Bike aria-hidden className="size-3.5" />
                            Ubah status
                        </DropdownMenuLabel>
                        <DropdownMenuRadioGroup
                            value={branch.status}
                            onValueChange={changeStatus}
                        >
                            {statuses.map((status) => (
                                <DropdownMenuRadioItem
                                    key={status.value}
                                    value={status.value}
                                >
                                    {status.label}
                                </DropdownMenuRadioItem>
                            ))}
                        </DropdownMenuRadioGroup>
                    </>
                )}

                {branch.can.delete && (
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

                {/* Shown disabled rather than hidden, so the reason the server
                    would refuse is visible instead of being discovered after
                    the confirmation dialog. */}
                {!branch.can.delete && branch.delete_blocked_reason && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            disabled
                            title={branch.delete_blocked_reason}
                        >
                            <Trash2 aria-hidden />
                            Hapus
                        </DropdownMenuItem>
                        <p className="px-2 py-1 text-xs text-muted-foreground">
                            {branch.delete_blocked_reason}
                        </p>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function DeleteBranchDialog({
    branch,
    onClose,
}: {
    branch: BranchListItem | null;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);

    const confirm = (): void => {
        if (!branch) {
            return;
        }

        router.delete(BranchController.destroy(branch.id).url, {
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
            open={branch !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Hapus cabang?</DialogTitle>
                    <DialogDescription>
                        Cabang <strong>{branch?.name}</strong> dihapus secara
                        lunak: gudang dan riwayatnya tetap tersimpan, tetapi
                        cabang tidak lagi muncul di storefront maupun panel.
                        Cabang yang masih memiliki staf tidak dapat dihapus.
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
                        Hapus cabang
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

BranchesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Cabang',
            href: branchesIndex(),
        },
    ],
};
