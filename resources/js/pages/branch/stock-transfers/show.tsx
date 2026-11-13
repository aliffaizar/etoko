import { Head, Link, router, setLayoutProps, useForm } from '@inertiajs/react';
import { ArrowLeft, Check, Circle, PackageCheck, Truck, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { StatusBadge } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime, formatNumber } from '@/lib/format';
import { TRANSFER_STATUS_TONE } from '@/lib/stock-transfer-status';
import stockTransfers from '@/routes/branch/stock-transfers';
import type {
    StockScopeOption,
    StockTransferDetail,
    StockTransferLine,
} from '@/types';

type Abilities = {
    approve: boolean;
    reject: boolean;
    ship: boolean;
    receive: boolean;
    cancel: boolean;
};

type StockTransferShowProps = {
    transfer: StockTransferDetail;
    /** The origin's warehouses, only for the end that approves. */
    originWarehouses: StockScopeOption[];
    can: Abilities;
};

type Step = 'approve' | 'ship' | 'receive';

const STEP_COPY: Record<
    Step,
    { column: string; title: string; description: string }
> = {
    approve: {
        column: 'Disetujui',
        title: 'Setujui permintaan',
        description:
            'Isi berapa yang sanggup dikirim per baris; 0 bila tidak ada. Menyetujui belum mengurangi stok.',
    },
    ship: {
        column: 'Dikirim',
        title: 'Kirim barang',
        description:
            'Isi jumlah yang benar-benar dikemas. Stok gudang asal berkurang saat tombol ditekan, dan barang tidak dihitung di cabang mana pun sampai diterima.',
    },
    receive: {
        column: 'Diterima',
        title: 'Terima barang',
        description:
            'Hitung yang benar-benar tiba. Hanya jumlah ini yang masuk ke gudang; kekurangan dicatat sebagai selisih.',
    },
};

/** The quantity a step starts from: whatever the step before settled on. */
function startingQuantity(line: StockTransferLine, step: Step): number {
    if (step === 'approve') {
        return line.quantity_requested;
    }

    if (step === 'ship') {
        return line.quantity_approved ?? 0;
    }

    return line.quantity_shipped ?? 0;
}

function ceilingFor(line: StockTransferLine, step: Step): number {
    return startingQuantity(line, step);
}

function figure(value: number | null): string {
    return value === null ? '—' : formatNumber(value);
}

/**
 * One stock transfer, with the step that is the viewer's to take (P2-E3-T3, T4).
 *
 * The lines table carries a column per step, so how the figure narrowed — asked,
 * agreed, packed, arrived — reads left to right. When it is the viewer's turn,
 * one more column holds the inputs for their step, prefilled with the figure
 * of the step before; they only change what differs.
 *
 * The origin's shelf figures appear only for the end that decides and packs.
 */
export default function StockTransferShow({
    transfer,
    originWarehouses,
    can,
}: StockTransferShowProps) {
    const step: Step | null = can.approve
        ? 'approve'
        : can.ship
          ? 'ship'
          : can.receive
            ? 'receive'
            : null;

    const [quantities, setQuantities] = useState<Record<number, number>>(() =>
        Object.fromEntries(
            transfer.items.map((line) => [
                line.id,
                step === null ? 0 : startingQuantity(line, step),
            ]),
        ),
    );
    const [fromWarehouseId, setFromWarehouseId] = useState(
        String(transfer.from_warehouse.id),
    );
    const [receiptNotes, setReceiptNotes] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [isRejecting, setIsRejecting] = useState(false);

    setLayoutProps({
        breadcrumbs: [
            { title: 'Transfer Stok', href: stockTransfers.index() },
            { title: transfer.code, href: stockTransfers.show(transfer.id) },
        ],
    });

    const showOriginStock = transfer.items.some(
        (line) => line.origin_on_hand !== null,
    );

    const isShort = useMemo(
        () =>
            step === 'receive' &&
            transfer.items.some(
                (line) =>
                    (quantities[line.id] ?? 0) < (line.quantity_shipped ?? 0),
            ),
        [quantities, step, transfer.items],
    );

    const submit = (): void => {
        if (step === null) {
            return;
        }

        const route = {
            approve: stockTransfers.approve,
            ship: stockTransfers.ship,
            receive: stockTransfers.receive,
        }[step];

        router.post(
            route(transfer.id).url,
            {
                items: transfer.items.map((line) => ({
                    id: line.id,
                    quantity: quantities[line.id] ?? 0,
                })),
                from_warehouse_id:
                    step === 'approve' ? Number(fromWarehouseId) : null,
                receipt_notes: step === 'receive' ? receiptNotes || null : null,
            },
            {
                preserveScroll: true,
                onStart: () => setIsSubmitting(true),
                onFinish: () => setIsSubmitting(false),
                onError: (received) => setErrors(received),
                onSuccess: () => setErrors({}),
            },
        );
    };

    const cancel = (): void => {
        router.post(
            stockTransfers.cancel(transfer.id).url,
            {},
            {
                preserveScroll: true,
                onError: (received) => setErrors(received),
            },
        );
    };

    return (
        <>
            <Head title={`Transfer ${transfer.code}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={transfer.code}
                        description={`${transfer.from_branch.name} (${transfer.from_warehouse.name}) → ${transfer.to_branch.name} (${transfer.to_warehouse.name})`}
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        <Button variant="outline" asChild>
                            <Link href={stockTransfers.index()}>
                                <ArrowLeft aria-hidden />
                                Kembali
                            </Link>
                        </Button>
                        <StatusBadge
                            label={transfer.status_label}
                            tone={
                                TRANSFER_STATUS_TONE[transfer.status] ??
                                'neutral'
                            }
                        />
                    </div>
                </div>

                {errors.status && (
                    <p className="rounded-lg border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive">
                        {errors.status}
                    </p>
                )}

                {transfer.rejection_reason && (
                    <p className="rounded-lg border border-destructive/40 bg-destructive/5 p-3 text-sm">
                        <strong>Alasan penolakan:</strong>{' '}
                        {transfer.rejection_reason}
                    </p>
                )}

                {transfer.total_discrepancy > 0 && (
                    <p className="rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-sm">
                        <strong>
                            Selisih {formatNumber(transfer.total_discrepancy)}{' '}
                            unit
                        </strong>{' '}
                        keluar dari cabang asal tetapi tidak tiba.
                        {transfer.receipt_notes
                            ? ` Catatan penerima: ${transfer.receipt_notes}`
                            : ''}
                    </p>
                )}

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="lg:col-span-1">
                        <CardHeader>
                            <CardTitle>Riwayat</CardTitle>
                            <CardDescription>
                                Setiap langkah dilakukan oleh pihak yang
                                berbeda.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <ol className="flex flex-col gap-3">
                                {transfer.steps.map((entry) => (
                                    <li
                                        key={entry.key}
                                        className="flex items-start gap-3"
                                    >
                                        <Circle
                                            aria-hidden
                                            className="mt-1 size-3 shrink-0 fill-current text-muted-foreground"
                                        />
                                        <div className="min-w-0 text-sm">
                                            <p className="font-medium">
                                                {entry.label}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {formatDateTime(entry.at)}
                                                {entry.by
                                                    ? ` · ${entry.by}`
                                                    : ''}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ol>

                            {transfer.notes && (
                                <div className="border-t pt-4 text-sm">
                                    <p className="text-xs text-muted-foreground">
                                        Catatan peminta
                                    </p>
                                    <p className="whitespace-pre-line">
                                        {transfer.notes}
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>
                                {step ? STEP_COPY[step].title : 'Baris barang'}
                            </CardTitle>
                            <CardDescription>
                                {step
                                    ? STEP_COPY[step].description
                                    : 'Kolom terisi saat langkahnya sudah terjadi.'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            {errors.items && (
                                <p className="text-sm text-destructive">
                                    {errors.items}
                                </p>
                            )}

                            <div className="overflow-x-auto rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Varian</TableHead>
                                            {showOriginStock && (
                                                <TableHead className="text-right">
                                                    Tersedia di asal
                                                </TableHead>
                                            )}
                                            <TableHead className="text-right">
                                                Diminta
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Disetujui
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Dikirim
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Diterima
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Selisih
                                            </TableHead>
                                            {step && (
                                                <TableHead className="text-right">
                                                    {STEP_COPY[step].column}{' '}
                                                    sekarang
                                                </TableHead>
                                            )}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {transfer.items.map((line) => (
                                            <TableRow key={line.id}>
                                                <TableCell>
                                                    <div className="min-w-0">
                                                        <p className="truncate text-sm font-medium">
                                                            {line.product_name}
                                                        </p>
                                                        <p className="truncate font-mono text-xs text-muted-foreground">
                                                            {line.sku}
                                                        </p>
                                                    </div>
                                                </TableCell>
                                                {showOriginStock && (
                                                    <TableCell className="text-right text-sm tabular-nums">
                                                        {figure(
                                                            line.origin_available,
                                                        )}
                                                    </TableCell>
                                                )}
                                                <TableCell className="text-right text-sm tabular-nums">
                                                    {figure(
                                                        line.quantity_requested,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right text-sm tabular-nums">
                                                    {figure(
                                                        line.quantity_approved,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right text-sm tabular-nums">
                                                    {figure(
                                                        line.quantity_shipped,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right text-sm tabular-nums">
                                                    {figure(
                                                        line.quantity_received,
                                                    )}
                                                </TableCell>
                                                <TableCell
                                                    className={
                                                        line.discrepancy > 0
                                                            ? 'text-right text-sm font-medium text-amber-700 tabular-nums dark:text-amber-400'
                                                            : 'text-right text-sm text-muted-foreground tabular-nums'
                                                    }
                                                >
                                                    {line.discrepancy > 0
                                                        ? formatNumber(
                                                              line.discrepancy,
                                                          )
                                                        : '—'}
                                                </TableCell>
                                                {step && (
                                                    <TableCell className="text-right">
                                                        <Input
                                                            type="number"
                                                            min={0}
                                                            max={ceilingFor(
                                                                line,
                                                                step,
                                                            )}
                                                            className="ml-auto w-24 text-right"
                                                            aria-label={`${STEP_COPY[step].column} ${line.sku}`}
                                                            value={
                                                                quantities[
                                                                    line.id
                                                                ] ?? 0
                                                            }
                                                            onChange={(event) =>
                                                                setQuantities({
                                                                    ...quantities,
                                                                    [line.id]:
                                                                        Number(
                                                                            event
                                                                                .target
                                                                                .value,
                                                                        ) || 0,
                                                                })
                                                            }
                                                        />
                                                    </TableCell>
                                                )}
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>

                            {step === 'approve' && (
                                <div className="grid gap-2 sm:max-w-sm">
                                    <Label htmlFor="from_warehouse_id">
                                        Dikirim dari gudang
                                    </Label>
                                    <Select
                                        value={fromWarehouseId}
                                        onValueChange={setFromWarehouseId}
                                    >
                                        <SelectTrigger
                                            id="from_warehouse_id"
                                            aria-label="Gudang asal"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectGroup>
                                                {originWarehouses.map(
                                                    (option) => (
                                                        <SelectItem
                                                            key={option.value}
                                                            value={option.value}
                                                        >
                                                            {option.label}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectGroup>
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={errors.from_warehouse_id}
                                    />
                                </div>
                            )}

                            {step === 'receive' && (
                                <div className="grid gap-2">
                                    <Label htmlFor="receipt_notes">
                                        Catatan penerimaan
                                        {!isShort && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                (opsional)
                                            </span>
                                        )}
                                    </Label>
                                    <Textarea
                                        id="receipt_notes"
                                        rows={3}
                                        value={receiptNotes}
                                        maxLength={1000}
                                        onChange={(event) =>
                                            setReceiptNotes(event.target.value)
                                        }
                                    />
                                    {isShort && (
                                        <p className="text-xs text-muted-foreground">
                                            Ada yang kurang dari jumlah dikirim.
                                            Jelaskan apa yang terjadi: rusak,
                                            hilang, atau belum tiba. Transfer
                                            akan ditutup sebagai "Diterima
                                            Sebagian".
                                        </p>
                                    )}
                                    <InputError
                                        message={errors.receipt_notes}
                                    />
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <div className="flex flex-wrap items-center justify-end gap-2">
                    {can.cancel && (
                        <Button variant="outline" onClick={cancel}>
                            <X aria-hidden />
                            Batalkan Permintaan
                        </Button>
                    )}
                    {can.reject && (
                        <Button
                            variant="outline"
                            className="text-destructive hover:text-destructive"
                            onClick={() => setIsRejecting(true)}
                        >
                            <X aria-hidden />
                            Tolak
                        </Button>
                    )}
                    {step && (
                        <Button disabled={isSubmitting} onClick={submit}>
                            {step === 'approve' && <Check aria-hidden />}
                            {step === 'ship' && <Truck aria-hidden />}
                            {step === 'receive' && <PackageCheck aria-hidden />}
                            {step === 'approve' && 'Setujui'}
                            {step === 'ship' && 'Kirim & Kurangi Stok'}
                            {step === 'receive' && 'Terima & Tambah Stok'}
                        </Button>
                    )}
                </div>
            </div>

            {can.reject && (
                <RejectDialog
                    transfer={transfer}
                    open={isRejecting}
                    onOpenChange={setIsRejecting}
                />
            )}
        </>
    );
}

function RejectDialog({
    transfer,
    open,
    onOpenChange,
}: {
    transfer: StockTransferDetail;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm<{ reason: string }>({ reason: '' });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(stockTransfers.reject(transfer.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
                form.reset();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>
                            Tolak transfer {transfer.code}?
                        </DialogTitle>
                        <DialogDescription>
                            Belum ada stok yang berpindah, jadi tidak ada yang
                            perlu dibalik. Alasan di bawah{' '}
                            <strong>dibaca {transfer.to_branch.name}</strong>.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2 py-4">
                        <Label htmlFor="reject-reason">Alasan penolakan</Label>
                        <Textarea
                            id="reject-reason"
                            rows={3}
                            maxLength={255}
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                        />
                        <InputError message={form.errors.reason} />
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Batal
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing}
                        >
                            Tolak Transfer
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
