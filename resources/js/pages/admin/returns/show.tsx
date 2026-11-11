import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Check, CheckCircle2, Images, Undo2, X } from 'lucide-react';
import { useState } from 'react';
import { ProcessRefundForm } from '@/components/admin/process-refund-form';
import type { RefundFormOptions } from '@/components/admin/process-refund-form';
import { ReceiveReturnForm } from '@/components/admin/receive-return-form';
import { RefundList } from '@/components/admin/refund-list';
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
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime, formatRupiah } from '@/lib/format';
import { show as orderShow } from '@/routes/admin/orders';
import returns, { index as returnsIndex } from '@/routes/admin/returns';
import { ORDER_STATUS_TONE, PAYMENT_STATUS_TONE } from '@/types/order';
import type { FilterOption } from '@/types';
import type { RefundRow, ReturnDetail } from '@/types/return';
import { RETURN_STATE_TONE } from '@/types/return';

type ReturnShowProps = {
    return: ReturnDetail;
    conditions: FilterOption[];
    refund_options: RefundFormOptions;
    refunds: RefundRow[];
    errors: Record<string, string>;
};

/**
 * One return request, with whatever may still be done about it (P2-E4-T3, T4).
 *
 * The screen is ordered the way the parcel moves: what was asked for and why,
 * then the decision, then the count when it arrives, then the money. Which
 * buttons appear is the **server's** decision — `can.*` is built from
 * `OrderReturnPolicy` and the `*BlockedReason()` methods on `OrderReturn` —
 * and where a button is withheld the reason is printed instead, because a
 * button offered for something the server will refuse is worse than no button.
 */
export default function ReturnShow({
    return: item,
    conditions,
    refund_options: refundOptions,
    refunds,
    errors,
}: ReturnShowProps) {
    const [rejecting, setRejecting] = useState(false);
    const approveForm = useForm<{ notes: string }>({ notes: '' });
    const completeForm = useForm<{ notes: string }>({ notes: '' });
    const order = item.order;

    return (
        <>
            <Head title={`Retur ${item.code}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-start gap-3">
                    <Button variant="ghost" size="icon" asChild>
                        <Link
                            href={returnsIndex().url}
                            aria-label="Kembali ke daftar retur"
                        >
                            <ArrowLeft aria-hidden />
                        </Link>
                    </Button>
                    <div className="min-w-0">
                        <h1 className="flex items-center gap-2 text-xl font-semibold">
                            <Undo2 aria-hidden className="size-5" />
                            Retur {item.code}
                        </h1>
                        <p className="text-xs text-muted-foreground">
                            {item.type_label} · {item.reason_label} ·{' '}
                            {item.item_count} unit
                        </p>
                    </div>
                </div>

                {errors.status && (
                    <Alert variant="destructive">
                        <AlertTitle>Retur tidak dapat diubah</AlertTitle>
                        <AlertDescription>
                            <p>{errors.status}</p>
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-6 xl:grid-cols-[1fr_20rem]">
                    <div className="flex min-w-0 flex-col gap-6">
                        <section className="flex flex-col gap-4 rounded-lg border p-4">
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusBadge
                                    label={item.status_label}
                                    tone={RETURN_STATE_TONE[item.status]}
                                />
                                <span className="text-xs text-muted-foreground">
                                    Diajukan {formatDateTime(item.requested_at)}
                                    {item.requested_by &&
                                        ` oleh ${item.requested_by}`}
                                </span>
                            </div>

                            {item.description && (
                                <div>
                                    <h2 className="text-sm font-semibold">
                                        Penjelasan pelanggan
                                    </h2>
                                    <p className="mt-1 text-sm whitespace-pre-line text-muted-foreground">
                                        {item.description}
                                    </p>
                                </div>
                            )}

                            {item.photo_urls.length > 0 && (
                                <div>
                                    <h2 className="flex items-center gap-2 text-sm font-semibold">
                                        <Images
                                            aria-hidden
                                            className="size-4"
                                        />
                                        Foto dari pelanggan
                                    </h2>
                                    <ul className="mt-2 flex flex-wrap gap-2">
                                        {item.photo_urls.map((url, index) => (
                                            <li key={url}>
                                                <a
                                                    href={url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                >
                                                    <img
                                                        src={url}
                                                        alt={`Foto barang retur ${index + 1}`}
                                                        className="size-24 rounded-md border object-cover"
                                                    />
                                                </a>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}

                            <div>
                                <h2 className="text-sm font-semibold">
                                    Barang yang diretur
                                </h2>
                                <ul className="mt-2 divide-y rounded-md border">
                                    {item.items.map((line) => (
                                        <li
                                            key={line.id}
                                            className="flex flex-wrap items-center justify-between gap-2 p-3"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium">
                                                    {line.display_name ??
                                                        'Barang tidak dikenal'}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {line.sku} · {line.quantity}{' '}
                                                    dari {line.ordered_quantity}{' '}
                                                    unit ·{' '}
                                                    {line.condition_label}
                                                    {line.restocked_at &&
                                                        ` · masuk ${line.restock_warehouse ?? 'stok'}`}
                                                </p>
                                                {line.notes && (
                                                    <p className="text-xs text-muted-foreground">
                                                        {line.notes}
                                                    </p>
                                                )}
                                            </div>
                                            <span className="text-sm font-medium tabular-nums">
                                                {formatRupiah(
                                                    line.refundable_value,
                                                )}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                                <div className="mt-2 flex justify-between gap-3 text-sm">
                                    <span className="text-muted-foreground">
                                        Nilai barang retur
                                    </span>
                                    <span className="font-medium tabular-nums">
                                        {formatRupiah(item.goods_value)}
                                    </span>
                                </div>
                                <div className="flex justify-between gap-3 text-sm">
                                    <span className="text-muted-foreground">
                                        Sudah dikembalikan
                                    </span>
                                    <span className="font-medium tabular-nums">
                                        {formatRupiah(item.refunded_amount)}
                                    </span>
                                </div>
                            </div>

                            {item.rejection_reason && (
                                <div className="rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm">
                                    <p className="font-medium">
                                        Alasan penolakan
                                    </p>
                                    <p className="text-muted-foreground">
                                        {item.rejection_reason}
                                    </p>
                                </div>
                            )}

                            {item.notes && (
                                <div className="rounded-md border p-3 text-sm">
                                    <p className="font-medium">
                                        Catatan internal
                                    </p>
                                    <p className="text-muted-foreground">
                                        {item.notes}
                                    </p>
                                </div>
                            )}

                            <div className="flex flex-wrap gap-2 border-t pt-3">
                                {item.can.approve && (
                                    <Button
                                        disabled={approveForm.processing}
                                        onClick={() =>
                                            approveForm.post(
                                                returns.approve(item.id).url,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Check aria-hidden />
                                        {approveForm.processing
                                            ? 'Menyetujui…'
                                            : 'Setujui retur'}
                                    </Button>
                                )}
                                {item.can.reject && (
                                    <Button
                                        variant="outline"
                                        onClick={() => setRejecting(true)}
                                    >
                                        <X aria-hidden />
                                        Tolak
                                    </Button>
                                )}
                                {item.can.complete && (
                                    <Button
                                        variant="ghost"
                                        disabled={completeForm.processing}
                                        onClick={() =>
                                            completeForm.post(
                                                returns.complete(item.id).url,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <CheckCircle2 aria-hidden />
                                        Tandai selesai
                                    </Button>
                                )}
                                {!item.can.approve &&
                                    !item.can.reject &&
                                    !item.can.receive &&
                                    !item.can.complete && (
                                        <p className="text-xs text-muted-foreground">
                                            {item.blocked.approve ??
                                                'Tidak ada tindakan yang tersedia untuk retur ini.'}
                                        </p>
                                    )}
                            </div>
                        </section>

                        {item.can.receive && (
                            <ReceiveReturnForm
                                item={item}
                                conditions={conditions}
                            />
                        )}

                        {item.can.refund && order && (
                            <ProcessRefundForm
                                orderId={order.id}
                                returnId={item.id}
                                options={refundOptions}
                                blockedReason={item.blocked.refund}
                            />
                        )}

                        <RefundList
                            refunds={refunds}
                            canProcess={item.can.process_refund ?? false}
                        />
                    </div>

                    <aside className="flex h-fit flex-col gap-4 rounded-lg border p-4">
                        <div>
                            <h2 className="mb-3 text-sm font-semibold">
                                Pesanan
                            </h2>

                            {order ? (
                                <div className="flex flex-col gap-2 text-sm">
                                    <Link
                                        href={orderShow(order.id).url}
                                        className="font-medium tabular-nums hover:underline"
                                    >
                                        {order.order_number}
                                    </Link>
                                    <p className="text-muted-foreground">
                                        {order.customer_name}
                                    </p>
                                    <div className="flex flex-wrap gap-2">
                                        <StatusBadge
                                            label={order.status_label}
                                            tone={
                                                ORDER_STATUS_TONE[order.status]
                                            }
                                        />
                                        <StatusBadge
                                            label={`Pembayaran: ${order.payment_status_label}`}
                                            tone={
                                                PAYMENT_STATUS_TONE[
                                                    order.payment_status
                                                ]
                                            }
                                        />
                                    </div>
                                    <div className="flex justify-between gap-3 border-t pt-2">
                                        <span className="text-muted-foreground">
                                            Total pesanan
                                        </span>
                                        <span className="font-medium tabular-nums">
                                            {formatRupiah(order.grand_total)}
                                        </span>
                                    </div>
                                    <div className="flex justify-between gap-3">
                                        <span className="text-muted-foreground">
                                            Sisa dapat dikembalikan
                                        </span>
                                        <span className="font-medium tabular-nums">
                                            {formatRupiah(
                                                refundOptions.refundable_amount,
                                            )}
                                        </span>
                                    </div>
                                    {item.branch && (
                                        <div className="flex justify-between gap-3">
                                            <span className="text-muted-foreground">
                                                Cabang
                                            </span>
                                            <span>{item.branch.name}</span>
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    Pesanan tidak dapat dimuat.
                                </p>
                            )}
                        </div>

                        <div className="border-t pt-3">
                            <h2 className="mb-2 text-sm font-semibold">
                                Riwayat retur
                            </h2>
                            <dl className="flex flex-col gap-1 text-sm">
                                <Moment
                                    label="Diajukan"
                                    value={item.requested_at}
                                />
                                <Moment
                                    label="Disetujui"
                                    value={item.approved_at}
                                    by={item.approved_by}
                                />
                                <Moment
                                    label="Barang diterima"
                                    value={item.received_at}
                                    by={item.received_by}
                                />
                                <Moment
                                    label="Selesai"
                                    value={item.completed_at}
                                />
                            </dl>
                        </div>
                    </aside>
                </div>
            </div>

            <RejectDialog
                item={item}
                open={rejecting}
                onOpenChange={setRejecting}
            />
        </>
    );
}

function Moment({
    label,
    value,
    by,
}: {
    label: string;
    value: string | null;
    by?: string | null;
}) {
    if (!value) {
        return null;
    }

    return (
        <div className="flex justify-between gap-3">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right">
                {formatDateTime(value)}
                {by && (
                    <span className="block text-xs text-muted-foreground">
                        {by}
                    </span>
                )}
            </dd>
        </div>
    );
}

function RejectDialog({
    item,
    open,
    onOpenChange,
}: {
    item: ReturnDetail;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm<{ reason: string }>({ reason: '' });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(returns.reject(item.id).url, {
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
                        <DialogTitle>Tolak pengajuan retur ini?</DialogTitle>
                        <DialogDescription>
                            Unit yang diajukan akan kembali tersedia untuk
                            diretur, jadi pelanggan dapat mengajukan ulang.
                            Alasan di bawah <strong>dibaca pelanggan</strong>,
                            jadi tulis apa yang harus mereka perbaiki.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="py-4">
                        <Label htmlFor={`reject-return-${item.id}`}>
                            Alasan penolakan
                        </Label>
                        <Textarea
                            id={`reject-return-${item.id}`}
                            className="mt-2"
                            rows={3}
                            maxLength={255}
                            placeholder="Mis. pengajuan melewati 7 hari sejak barang diterima."
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                        />
                        {form.errors.reason && (
                            <p className="mt-2 text-sm text-destructive">
                                {form.errors.reason}
                            </p>
                        )}
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
                            {form.processing ? 'Menolak…' : 'Tolak retur'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
