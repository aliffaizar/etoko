import { Head, Link, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRightLeft,
    Ban,
    Lock,
    MapPin,
    Package,
    ShoppingBag,
    Store,
    Truck,
    User,
} from 'lucide-react';
import { useState } from 'react';
import { OrderTimeline } from '@/components/order-timeline';
import Heading from '@/components/heading';
import { StatusBadge } from '@/components/panel/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime, formatRupiah, formatWeight } from '@/lib/format';
import { show as customerShow } from '@/routes/admin/customers';
import orders from '@/routes/admin/orders';
import type { FilterOption } from '@/types';
import {
    ORDER_STATUS_TONE,
    PAYMENT_STATUS_TONE,
    type OrderDetail,
    type OrderReservationRow,
} from '@/types/order';

type OrderShowProps = {
    order: OrderDetail;
    reservations: OrderReservationRow[];
    blocked: { cancel: string | null; assign_branch: string | null };
    next_statuses: FilterOption[];
    branches: FilterOption[];
};

const RESERVATION_TONE = {
    active: 'warning',
    committed: 'success',
    released: 'neutral',
} as const;

export default function OrderShow({
    order,
    reservations,
    blocked,
    next_statuses: nextStatuses,
    branches,
}: OrderShowProps) {
    const [cancelling, setCancelling] = useState(false);
    const [moving, setMoving] = useState(false);

    const statusForm = useForm<{ status: string; note: string }>({
        status: nextStatuses[0]?.value ?? '',
        note: '',
    });
    const cancelForm = useForm<{ reason: string }>({ reason: '' });
    const branchForm = useForm<{ branch_id: string; note: string }>({
        branch_id: branches[0]?.value ?? '',
        note: '',
    });

    const submitStatus = (event: React.FormEvent) => {
        event.preventDefault();
        statusForm.put(orders.status.update(order.id).url, {
            preserveScroll: true,
            onSuccess: () => statusForm.setData('note', ''),
        });
    };

    const submitCancel = (event: React.FormEvent) => {
        event.preventDefault();
        cancelForm.post(orders.cancel(order.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                setCancelling(false);
                cancelForm.reset();
            },
        });
    };

    const submitBranch = (event: React.FormEvent) => {
        event.preventDefault();
        branchForm.put(orders.branch.update(order.id).url, {
            preserveScroll: true,
            onSuccess: () => setMoving(false),
        });
    };

    const FulfillmentIcon = order.fulfillment_type === 'pickup' ? Store : Truck;

    const errorMessage =
        statusForm.errors.status ??
        cancelForm.errors.reason ??
        branchForm.errors.branch_id;

    return (
        <>
            <Head title={`Pesanan ${order.order_number}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center gap-3">
                    <Button variant="ghost" size="icon" asChild>
                        <Link
                            href={orders.index().url}
                            aria-label="Kembali ke daftar pesanan"
                        >
                            <ArrowLeft aria-hidden />
                        </Link>
                    </Button>
                    <Heading
                        title={order.order_number}
                        description={`Dibuat ${formatDateTime(order.created_at)} · ${order.branch?.name ?? 'tanpa cabang'}`}
                    />
                </div>

                {errorMessage && (
                    <Alert variant="destructive">
                        <AlertTitle>Tindakan ditolak</AlertTitle>
                        <AlertDescription>
                            <p>{errorMessage}</p>
                        </AlertDescription>
                    </Alert>
                )}

                <div className="flex flex-wrap items-center gap-2">
                    <StatusBadge
                        label={order.status_label}
                        tone={ORDER_STATUS_TONE[order.status]}
                    />
                    <StatusBadge
                        label={`Pembayaran: ${order.payment_status_label}`}
                        tone={PAYMENT_STATUS_TONE[order.payment_status]}
                    />
                    <span className="flex items-center gap-1.5 text-sm text-muted-foreground">
                        <FulfillmentIcon aria-hidden className="size-4" />
                        {order.fulfillment_label}
                    </span>
                    <span className="text-sm text-muted-foreground">
                        Kanal: {order.channel === 'pos' ? 'Kasir' : 'Online'}
                    </span>
                </div>

                <div className="grid gap-4 xl:grid-cols-[1fr_22rem]">
                    <div className="flex flex-col gap-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Package aria-hidden className="size-4" />
                                    {order.item_count} item
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <ul className="divide-y">
                                    {order.items.map((item) => (
                                        <li
                                            key={item.id}
                                            className="flex gap-3 py-3 first:pt-0 last:pb-0"
                                        >
                                            <div className="size-12 shrink-0 overflow-hidden rounded-md border bg-muted">
                                                {item.image_url ? (
                                                    <img
                                                        src={item.image_url}
                                                        alt=""
                                                        className="size-full object-cover"
                                                    />
                                                ) : (
                                                    <div className="flex size-full items-center justify-center">
                                                        <ShoppingBag
                                                            aria-hidden
                                                            className="size-4 text-muted-foreground"
                                                        />
                                                    </div>
                                                )}
                                            </div>

                                            <div className="min-w-0 flex-1 text-sm">
                                                <p className="truncate font-medium">
                                                    {item.display_name}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {item.sku} ·{' '}
                                                    {formatWeight(
                                                        item.weight_gram,
                                                    )}
                                                    /unit
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {item.quantity} ×{' '}
                                                    {formatRupiah(
                                                        item.unit_price,
                                                    )}
                                                    {item.refunded_quantity >
                                                        0 &&
                                                        ` · ${item.refunded_quantity} dikembalikan`}
                                                </p>
                                            </div>

                                            <p className="shrink-0 text-sm font-medium tabular-nums">
                                                {formatRupiah(item.subtotal)}
                                            </p>
                                        </li>
                                    ))}
                                </ul>

                                <dl className="mt-4 flex flex-col gap-2 border-t pt-4 text-sm">
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">
                                            Subtotal
                                        </dt>
                                        <dd className="tabular-nums">
                                            {formatRupiah(
                                                order.totals.subtotal,
                                            )}
                                        </dd>
                                    </div>
                                    {order.totals.discount_total > 0 && (
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-muted-foreground">
                                                Diskon
                                                {order.voucher_code
                                                    ? ` (${order.voucher_code})`
                                                    : ''}
                                            </dt>
                                            <dd className="tabular-nums">
                                                −
                                                {formatRupiah(
                                                    order.totals.discount_total,
                                                )}
                                            </dd>
                                        </div>
                                    )}
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">
                                            Ongkos kirim
                                        </dt>
                                        <dd className="tabular-nums">
                                            {formatRupiah(
                                                order.totals.shipping_cost,
                                            )}
                                        </dd>
                                    </div>
                                    {order.totals.tax_total > 0 && (
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-muted-foreground">
                                                Pajak
                                            </dt>
                                            <dd className="tabular-nums">
                                                {formatRupiah(
                                                    order.totals.tax_total,
                                                )}
                                            </dd>
                                        </div>
                                    )}
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">
                                            Berat total
                                        </dt>
                                        <dd className="tabular-nums">
                                            {formatWeight(
                                                order.totals.total_weight_gram,
                                            )}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-4 border-t pt-2 font-semibold">
                                        <dt>Total</dt>
                                        <dd className="tabular-nums">
                                            {formatRupiah(
                                                order.totals.grand_total,
                                            )}
                                        </dd>
                                    </div>
                                </dl>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Lock aria-hidden className="size-4" />
                                    Stok yang ditahan
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {reservations.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Tidak ada penahanan stok untuk pesanan
                                        ini.
                                    </p>
                                ) : (
                                    <ul className="divide-y text-sm">
                                        {reservations.map((reservation) => (
                                            <li
                                                key={reservation.id}
                                                className="flex flex-wrap items-center justify-between gap-3 py-2 first:pt-0 last:pb-0"
                                            >
                                                <div className="min-w-0">
                                                    <p className="truncate font-medium">
                                                        {reservation.sku ?? '-'}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {reservation.warehouse_name ??
                                                            '-'}
                                                        {reservation.expires_at
                                                            ? ` · berlaku s.d. ${formatDateTime(reservation.expires_at)}`
                                                            : ''}
                                                    </p>
                                                </div>
                                                <div className="flex items-center gap-3">
                                                    <StatusBadge
                                                        label={
                                                            reservation.status_label
                                                        }
                                                        tone={
                                                            RESERVATION_TONE[
                                                                reservation
                                                                    .status
                                                            ]
                                                        }
                                                    />
                                                    <span className="tabular-nums">
                                                        {reservation.quantity}{' '}
                                                        unit
                                                    </span>
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Riwayat status
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <OrderTimeline
                                    entries={order.timeline}
                                    showActor
                                />
                            </CardContent>
                        </Card>
                    </div>

                    <aside className="flex flex-col gap-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Ubah status
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {!order.can.update_status ||
                                nextStatuses.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Pesanan berstatus &ldquo;
                                        {order.status_label}&rdquo; tidak dapat
                                        dipindahkan lagi.
                                    </p>
                                ) : (
                                    <form
                                        onSubmit={submitStatus}
                                        className="flex flex-col gap-3"
                                    >
                                        <div>
                                            <Label htmlFor="status">
                                                Status berikutnya
                                            </Label>
                                            <Select
                                                value={statusForm.data.status}
                                                onValueChange={(value) =>
                                                    statusForm.setData(
                                                        'status',
                                                        value,
                                                    )
                                                }
                                            >
                                                <SelectTrigger
                                                    id="status"
                                                    className="mt-2 w-full"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {nextStatuses.map(
                                                        (status) => (
                                                            <SelectItem
                                                                key={
                                                                    status.value
                                                                }
                                                                value={
                                                                    status.value
                                                                }
                                                            >
                                                                {status.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </div>

                                        <div>
                                            <Label htmlFor="status-note">
                                                Catatan (opsional)
                                            </Label>
                                            <Textarea
                                                id="status-note"
                                                className="mt-2"
                                                rows={2}
                                                maxLength={500}
                                                value={statusForm.data.note}
                                                onChange={(event) =>
                                                    statusForm.setData(
                                                        'note',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </div>

                                        <Button
                                            type="submit"
                                            disabled={statusForm.processing}
                                        >
                                            {statusForm.processing
                                                ? 'Menyimpan…'
                                                : 'Simpan status'}
                                        </Button>

                                        <p className="text-xs text-muted-foreground">
                                            Menandai pesanan sudah dibayar akan
                                            mengeluarkan stoknya dari gudang.
                                        </p>
                                    </form>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <User aria-hidden className="size-4" />
                                    Pelanggan
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="text-sm">
                                <p className="font-medium">
                                    {order.customer_id ? (
                                        <Link
                                            href={
                                                customerShow(order.customer_id)
                                                    .url
                                            }
                                            className="hover:underline"
                                        >
                                            {order.customer_name}
                                        </Link>
                                    ) : (
                                        order.customer_name
                                    )}
                                </p>
                                <p className="text-muted-foreground">
                                    {order.customer_email ?? '-'}
                                </p>
                                <p className="text-muted-foreground">
                                    {order.customer_phone ?? '-'}
                                </p>

                                <div className="mt-4 flex items-start gap-2 border-t pt-4">
                                    {order.shipping ? (
                                        <MapPin
                                            aria-hidden
                                            className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                        />
                                    ) : (
                                        <Store
                                            aria-hidden
                                            className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                        />
                                    )}
                                    {order.shipping ? (
                                        <div className="min-w-0">
                                            <p className="font-medium">
                                                {order.shipping.recipient}
                                            </p>
                                            <p className="text-muted-foreground">
                                                {order.shipping.address_line},{' '}
                                                {order.shipping.district_name},{' '}
                                                {order.shipping.city_name},{' '}
                                                {order.shipping.province_name}{' '}
                                                {order.shipping.postal_code}
                                            </p>
                                            <p className="text-muted-foreground">
                                                {order.shipping.phone}
                                            </p>
                                        </div>
                                    ) : (
                                        <p className="text-muted-foreground">
                                            Diambil di{' '}
                                            {order.branch?.name ?? 'cabang'}.
                                        </p>
                                    )}
                                </div>

                                {order.customer_note && (
                                    <div className="mt-4 border-t pt-4">
                                        <p className="font-medium">
                                            Catatan pelanggan
                                        </p>
                                        <p className="text-muted-foreground">
                                            {order.customer_note}
                                        </p>
                                    </div>
                                )}

                                {order.cancel_reason && (
                                    <div className="mt-4 border-t pt-4">
                                        <p className="font-medium">
                                            Alasan pembatalan
                                        </p>
                                        <p className="text-muted-foreground">
                                            {order.cancel_reason}
                                        </p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Tindakan lain
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-3">
                                {order.can.assign_branch &&
                                branches.length > 0 ? (
                                    <Button
                                        variant="outline"
                                        onClick={() => setMoving(true)}
                                    >
                                        <ArrowRightLeft aria-hidden />
                                        Pindahkan cabang pemroses
                                    </Button>
                                ) : (
                                    blocked.assign_branch && (
                                        <p className="text-xs text-muted-foreground">
                                            {blocked.assign_branch}
                                        </p>
                                    )
                                )}

                                {order.can.cancel ? (
                                    <Button
                                        variant="outline"
                                        className="text-destructive hover:text-destructive"
                                        onClick={() => setCancelling(true)}
                                    >
                                        <Ban aria-hidden />
                                        Batalkan pesanan
                                    </Button>
                                ) : (
                                    blocked.cancel && (
                                        <p className="text-xs text-muted-foreground">
                                            {blocked.cancel}
                                        </p>
                                    )
                                )}
                            </CardContent>
                        </Card>
                    </aside>
                </div>
            </div>

            <Dialog open={cancelling} onOpenChange={setCancelling}>
                <DialogContent>
                    <form onSubmit={submitCancel}>
                        <DialogHeader>
                            <DialogTitle>
                                Batalkan {order.order_number}?
                            </DialogTitle>
                            <DialogDescription>
                                Stok yang masih ditahan akan dilepas dan kembali
                                dijual. Dana yang sudah diterima harus
                                dikembalikan lewat menu pembayaran secara
                                terpisah.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="py-4">
                            <Label htmlFor="cancel-reason">
                                Alasan (opsional)
                            </Label>
                            <Input
                                id="cancel-reason"
                                className="mt-2"
                                maxLength={255}
                                value={cancelForm.data.reason}
                                onChange={(event) =>
                                    cancelForm.setData(
                                        'reason',
                                        event.target.value,
                                    )
                                }
                            />
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setCancelling(false)}
                            >
                                Tidak, kembali
                            </Button>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={cancelForm.processing}
                            >
                                {cancelForm.processing
                                    ? 'Membatalkan…'
                                    : 'Ya, batalkan'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={moving} onOpenChange={setMoving}>
                <DialogContent>
                    <form onSubmit={submitBranch}>
                        <DialogHeader>
                            <DialogTitle>Pindahkan cabang pemroses</DialogTitle>
                            <DialogDescription>
                                Stok akan ditahan di cabang tujuan lebih dulu;
                                penahanan di cabang asal baru dilepas setelah
                                itu berhasil. Bila stok cabang tujuan tidak
                                cukup, pemindahan dibatalkan dan tidak ada yang
                                berubah.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="flex flex-col gap-3 py-4">
                            <div>
                                <Label htmlFor="branch_id">Cabang tujuan</Label>
                                <Select
                                    value={branchForm.data.branch_id}
                                    onValueChange={(value) =>
                                        branchForm.setData('branch_id', value)
                                    }
                                >
                                    <SelectTrigger
                                        id="branch_id"
                                        className="mt-2 w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {branches.map((branch) => (
                                            <SelectItem
                                                key={branch.value}
                                                value={branch.value}
                                            >
                                                {branch.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label htmlFor="branch-note">
                                    Catatan (opsional)
                                </Label>
                                <Textarea
                                    id="branch-note"
                                    className="mt-2"
                                    rows={2}
                                    maxLength={500}
                                    value={branchForm.data.note}
                                    onChange={(event) =>
                                        branchForm.setData(
                                            'note',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setMoving(false)}
                            >
                                Batal
                            </Button>
                            <Button
                                type="submit"
                                disabled={branchForm.processing}
                            >
                                {branchForm.processing
                                    ? 'Memindahkan…'
                                    : 'Pindahkan'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
