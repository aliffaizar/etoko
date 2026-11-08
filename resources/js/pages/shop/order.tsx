import { Head, Link, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
    MapPin,
    Package,
    ShoppingBag,
    Store,
    Truck,
} from 'lucide-react';
import { useState } from 'react';
import { OrderTimeline } from '@/components/order-timeline';
import { PaymentPanel } from '@/components/shop/payment-panel';
import { StatusBadge } from '@/components/panel/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
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
import { Separator } from '@/components/ui/separator';
import { formatDateTime, formatRupiah, formatWeight } from '@/lib/format';
import account from '@/routes/shop/account';
import { ORDER_STATUS_TONE, PAYMENT_STATUS_TONE } from '@/types/order';
import type { OrderDetail } from '@/types/order';
import type { CustomerPayment, PaymentMethodChoice } from '@/types/payment';

type OrderProps = {
    order: OrderDetail;
    cancel_blocked_reason: string | null;
    payment_blocked_reason: string | null;
    /** The attempt in progress, or the one that most recently failed. */
    payment: CustomerPayment | null;
    payment_history: CustomerPayment[];
    payment_methods: PaymentMethodChoice[];
    just_placed: boolean;
};

export default function OrderPage({
    order,
    cancel_blocked_reason: cancelBlockedReason,
    payment_blocked_reason: paymentBlockedReason,
    payment,
    payment_history: paymentHistory,
    payment_methods: paymentMethods,
    just_placed: justPlaced,
}: OrderProps) {
    const [cancelling, setCancelling] = useState(false);
    const cancelForm = useForm<{ reason: string }>({ reason: '' });

    const submitCancel = (event: React.FormEvent) => {
        event.preventDefault();
        cancelForm.post(account.orders.cancel(order.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                setCancelling(false);
                cancelForm.reset();
            },
        });
    };

    const FulfillmentIcon = order.fulfillment_type === 'pickup' ? Store : Truck;

    return (
        <>
            <Head title={`Pesanan ${order.order_number}`} />

            <div className="mx-auto flex max-w-4xl flex-col gap-6 px-4 py-8">
                <div className="flex items-center gap-3">
                    <Button variant="ghost" size="icon" asChild>
                        <Link
                            href={account.orders.index().url}
                            aria-label="Kembali ke daftar pesanan"
                        >
                            <ArrowLeft aria-hidden />
                        </Link>
                    </Button>
                    <div className="min-w-0">
                        <h1 className="text-xl font-semibold tabular-nums">
                            {order.order_number}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Dibuat {formatDateTime(order.created_at)}
                        </p>
                    </div>
                </div>

                {justPlaced && (
                    <Alert>
                        <CheckCircle2 aria-hidden />
                        <AlertTitle>Pesanan Anda sudah masuk</AlertTitle>
                        <AlertDescription>
                            {/* No "Cabang" prefix: the branch name already
                                carries the word ("Cabang Jakarta Pusat"). */}
                            <p>
                                Stok sudah ditahan untuk Anda. Selesaikan
                                pembayarannya di panel di bawah;{' '}
                                {order.branch?.name ?? 'cabang pemroses'} akan
                                mengonfirmasinya.
                            </p>
                        </AlertDescription>
                    </Alert>
                )}

                {cancelForm.errors.reason && (
                    <Alert variant="destructive">
                        <AlertTitle>Pembatalan ditolak</AlertTitle>
                        <AlertDescription>
                            <p>{cancelForm.errors.reason}</p>
                        </AlertDescription>
                    </Alert>
                )}

                <div className="flex flex-wrap items-center gap-2 rounded-lg border p-4">
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

                    <div className="ms-auto">
                        {order.can.cancel ? (
                            <Button
                                variant="outline"
                                onClick={() => setCancelling(true)}
                            >
                                Batalkan pesanan
                            </Button>
                        ) : (
                            cancelBlockedReason && (
                                <p className="max-w-xs text-xs text-muted-foreground">
                                    {cancelBlockedReason}
                                </p>
                            )
                        )}
                    </div>
                </div>

                {order.cancel_reason && (
                    <div className="rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm">
                        <p className="font-medium">Alasan pembatalan</p>
                        <p className="text-muted-foreground">
                            {order.cancel_reason}
                        </p>
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-[1fr_18rem]">
                    <div className="flex flex-col gap-6">
                        <section className="rounded-lg border p-4">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold">
                                <Package aria-hidden className="size-4" />
                                {order.item_count} item
                            </h2>

                            <ul className="divide-y">
                                {order.items.map((item) => (
                                    <li
                                        key={item.id}
                                        className="flex gap-3 py-3 first:pt-0 last:pb-0"
                                    >
                                        <div className="size-14 shrink-0 overflow-hidden rounded-md border bg-muted">
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
                                                        className="size-5 text-muted-foreground"
                                                    />
                                                </div>
                                            )}
                                        </div>

                                        <div className="min-w-0 flex-1 text-sm">
                                            {item.product_slug ? (
                                                <Link
                                                    href={`/produk/${item.product_slug}`}
                                                    className="truncate font-medium hover:underline"
                                                >
                                                    {item.product_name}
                                                </Link>
                                            ) : (
                                                <p className="truncate font-medium">
                                                    {item.product_name}
                                                </p>
                                            )}
                                            <p className="truncate text-muted-foreground">
                                                {item.variant_name ?? item.sku}
                                            </p>
                                            <p className="text-muted-foreground">
                                                {item.quantity} ×{' '}
                                                {formatRupiah(item.unit_price)}
                                            </p>
                                        </div>

                                        <p className="shrink-0 text-sm font-medium tabular-nums">
                                            {formatRupiah(item.subtotal)}
                                        </p>
                                    </li>
                                ))}
                            </ul>

                            <Separator className="my-4" />

                            <dl className="flex flex-col gap-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">
                                        Subtotal
                                    </dt>
                                    <dd className="tabular-nums">
                                        {formatRupiah(order.totals.subtotal)}
                                    </dd>
                                </div>
                                {order.totals.discount_total > 0 && (
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">
                                            Diskon
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
                                        {order.totals.shipping_cost === 0
                                            ? 'Gratis'
                                            : formatRupiah(
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
                                        {formatRupiah(order.totals.grand_total)}
                                    </dd>
                                </div>
                            </dl>
                        </section>

                        {order.customer_note && (
                            <section className="rounded-lg border p-4 text-sm">
                                <h2 className="mb-1 font-semibold">
                                    Catatan Anda
                                </h2>
                                <p className="text-muted-foreground">
                                    {order.customer_note}
                                </p>
                            </section>
                        )}
                    </div>

                    <aside className="flex flex-col gap-6">
                        <PaymentPanel
                            orderId={order.id}
                            payment={payment}
                            methods={paymentMethods}
                            blockedReason={paymentBlockedReason}
                            canPay={order.can.pay ?? false}
                            history={paymentHistory}
                        />

                        <section className="rounded-lg border p-4 text-sm">
                            <h2 className="mb-2 flex items-center gap-2 font-semibold">
                                {order.shipping ? (
                                    <MapPin aria-hidden className="size-4" />
                                ) : (
                                    <Store aria-hidden className="size-4" />
                                )}
                                {order.shipping
                                    ? 'Dikirim ke'
                                    : 'Diambil di cabang'}
                            </h2>

                            {order.shipping ? (
                                <>
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
                                </>
                            ) : (
                                <p className="text-muted-foreground">
                                    {order.branch?.name ?? '-'}
                                </p>
                            )}
                        </section>

                        <section className="rounded-lg border p-4">
                            <h2 className="mb-3 text-sm font-semibold">
                                Riwayat status
                            </h2>
                            <OrderTimeline entries={order.timeline} />
                        </section>
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
                                Stok yang ditahan akan dilepas dan kembali
                                dijual. Pesanan yang sudah dibatalkan tidak
                                dapat dipulihkan.
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
                                placeholder="Mis. salah pilih varian"
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
        </>
    );
}
