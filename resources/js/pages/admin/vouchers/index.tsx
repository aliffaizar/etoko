import { Head, Link, router } from '@inertiajs/react';
import {
    MoreHorizontal,
    Pencil,
    Plus,
    TicketPercent,
    ToggleLeft,
    ToggleRight,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import VoucherController from '@/actions/App/Http/Controllers/Admin/VoucherController';
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
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useTableQuery } from '@/hooks/use-table-query';
import { formatDateShort, formatRupiah } from '@/lib/format';
import { index as vouchersIndex } from '@/routes/admin/vouchers';
import type {
    DataTableColumn,
    FilterField,
    FilterOption,
    PaginatedVouchers,
    VoucherListItem,
} from '@/types';

type VouchersIndexProps = {
    vouchers: PaginatedVouchers;
    options: { states: FilterOption[]; types: FilterOption[] };
    can: { create: boolean };
};

/**
 * The voucher listing (P2-E5-T2).
 *
 * The state badge is worked out by the server from the switch, the period and
 * the quota (`Voucher::state()`), and the state filter asks the same rule as a
 * query, so a row never shows a state its filter would not find it under.
 */
export default function VouchersIndex({
    vouchers,
    options,
    can,
}: VouchersIndexProps) {
    const [pendingDelete, setPendingDelete] = useState<VoucherListItem | null>(
        null,
    );

    const table = useTableQuery({
        only: ['vouchers', 'filters'],
        defaultSort: 'created_at',
        defaultDirection: 'desc',
    });

    const filterFields: FilterField[] = [
        {
            type: 'search',
            key: 'search',
            label: 'Cari voucher',
            placeholder: 'Cari kode atau nama…',
        },
        {
            type: 'select',
            key: 'state',
            label: 'Status',
            options: options.states,
            allLabel: 'Semua status',
            className: 'sm:w-44',
        },
        {
            type: 'select',
            key: 'type',
            label: 'Jenis',
            options: options.types,
            allLabel: 'Semua jenis',
            className: 'sm:w-44',
        },
    ];

    const addButton = can.create && (
        <Button asChild>
            <Link href={VoucherController.create()}>
                <Plus aria-hidden />
                Tambah Voucher
            </Link>
        </Button>
    );

    const columns: DataTableColumn<VoucherListItem>[] = [
        {
            key: 'code',
            header: 'Voucher',
            sortKey: 'code',
            cell: (voucher) => (
                <div className="min-w-0">
                    <p className="truncate font-mono font-medium">
                        {voucher.code}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                        {voucher.name}
                    </p>
                </div>
            ),
        },
        {
            key: 'benefit',
            header: 'Potongan',
            cell: (voucher) => (
                <div className="min-w-0 text-sm">
                    <p className="truncate">{voucher.benefit}</p>
                    {voucher.min_order_amount > 0 && (
                        <p className="truncate text-xs text-muted-foreground">
                            Min. belanja{' '}
                            {formatRupiah(voucher.min_order_amount)}
                        </p>
                    )}
                </div>
            ),
        },
        {
            key: 'used_count',
            header: 'Terpakai',
            align: 'right',
            sortKey: 'used_count',
            hideOnMobile: true,
            cell: (voucher) => (
                <span className="text-sm tabular-nums">
                    {voucher.used_count}
                    {voucher.quota !== null && (
                        <span className="text-muted-foreground">
                            {' '}
                            / {voucher.quota}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'ends_at',
            header: 'Periode',
            sortKey: 'ends_at',
            hideOnMobile: true,
            cell: (voucher) => (
                <span className="text-sm text-muted-foreground">
                    {voucher.starts_at || voucher.ends_at
                        ? `${voucher.starts_at ? formatDateShort(voucher.starts_at) : 'Sekarang'} – ${voucher.ends_at ? formatDateShort(voucher.ends_at) : 'Tanpa batas'}`
                        : 'Tanpa batas waktu'}
                </span>
            ),
        },
        {
            key: 'state',
            header: 'Status',
            cell: (voucher) => (
                <StatusBadge
                    label={voucher.state.label}
                    tone={voucher.state.tone}
                />
            ),
        },
        {
            key: 'actions',
            header: 'Aksi',
            srOnlyHeader: true,
            align: 'right',
            cell: (voucher) => (
                <RowActions
                    voucher={voucher}
                    onRequestDelete={() => setPendingDelete(voucher)}
                />
            ),
        },
    ];

    return (
        <>
            <Head title="Voucher" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Voucher"
                    description="Kode potongan untuk pelanggan. Berlaku di semua cabang kecuali dibatasi."
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
                    rows={vouchers.data}
                    rowKey={(voucher) => voucher.id}
                    paginator={vouchers}
                    rowLabel="voucher"
                    sort={table.sort}
                    onSortChange={table.toggleSort}
                    goToPage={table.goToPage}
                    pageUrl={table.pageUrl}
                    isLoading={table.isLoading}
                    empty={{
                        icon: TicketPercent,
                        title: table.isFiltered
                            ? 'Tidak ada voucher yang cocok'
                            : 'Belum ada voucher',
                        description: table.isFiltered
                            ? 'Ubah atau hapus filter untuk melihat voucher lain.'
                            : 'Buat voucher pertama untuk memberi potongan kepada pelanggan.',
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

            <DeleteVoucherDialog
                voucher={pendingDelete}
                onClose={() => setPendingDelete(null)}
            />
        </>
    );
}

function RowActions({
    voucher,
    onRequestDelete,
}: {
    voucher: VoucherListItem;
    onRequestDelete: () => void;
}) {
    if (!voucher.can.update && !voucher.can.delete) {
        return null;
    }

    const toggleStatus = (): void => {
        router.patch(
            VoucherController.updateStatus(voucher.id).url,
            { is_active: !voucher.is_active },
            { preserveScroll: true },
        );
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label={`Aksi untuk ${voucher.code}`}
                >
                    <MoreHorizontal aria-hidden />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {voucher.can.update && (
                    <>
                        <DropdownMenuItem asChild>
                            <Link href={VoucherController.edit(voucher.id)}>
                                <Pencil aria-hidden />
                                Ubah voucher
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={toggleStatus}>
                            {voucher.is_active ? (
                                <ToggleLeft aria-hidden />
                            ) : (
                                <ToggleRight aria-hidden />
                            )}
                            {voucher.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                        </DropdownMenuItem>
                    </>
                )}

                {voucher.can.delete && (
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

                {!voucher.can.delete && voucher.delete_blocked_reason && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            disabled
                            title={voucher.delete_blocked_reason}
                        >
                            <Trash2 aria-hidden />
                            Hapus
                        </DropdownMenuItem>
                        <p className="max-w-64 px-2 py-1 text-xs text-muted-foreground">
                            {voucher.delete_blocked_reason}
                        </p>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function DeleteVoucherDialog({
    voucher,
    onClose,
}: {
    voucher: VoucherListItem | null;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);

    const confirm = (): void => {
        if (!voucher) {
            return;
        }

        router.delete(VoucherController.destroy(voucher.id).url, {
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
            open={voucher !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Hapus voucher?</DialogTitle>
                    <DialogDescription>
                        Voucher <strong>{voucher?.code}</strong> dihapus
                        permanen. Voucher yang sudah pernah dipakai tidak bisa
                        dihapus — nonaktifkan saja.
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
                        Hapus voucher
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

VouchersIndex.layout = {
    breadcrumbs: [
        {
            title: 'Voucher',
            href: vouchersIndex(),
        },
    ],
};
