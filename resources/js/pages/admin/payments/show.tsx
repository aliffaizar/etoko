import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Receipt } from 'lucide-react';
import { PaymentCard } from '@/components/admin/payment-card';
import { StatusBadge } from '@/components/panel/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { formatRupiah } from '@/lib/format';
import { show as orderShow } from '@/routes/admin/orders';
import { index as paymentsIndex } from '@/routes/admin/payments';
import { ORDER_STATUS_TONE, PAYMENT_STATUS_TONE } from '@/types/order';
import type { OrderPaymentStatus, OrderStatus } from '@/types/order';
import type { PaymentDetail } from '@/types/payment';

type PaymentShowProps = {
    payment: PaymentDetail;
    blocked: { settle: string | null };
    errors: Record<string, string>;
};

export default function PaymentShow({
    payment,
    blocked,
    errors,
}: PaymentShowProps) {
    const order = payment.order;

    return (
        <>
            <Head title={`Pembayaran ${payment.reference}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-start gap-3">
                    <Button variant="ghost" size="icon" asChild>
                        <Link
                            href={paymentsIndex().url}
                            aria-label="Kembali ke daftar pembayaran"
                        >
                            <ArrowLeft aria-hidden />
                        </Link>
                    </Button>
                    <div className="min-w-0">
                        <h1 className="flex items-center gap-2 text-xl font-semibold">
                            <Receipt aria-hidden className="size-5" />
                            Pembayaran
                        </h1>
                        <p className="font-mono text-xs break-all text-muted-foreground">
                            {payment.reference}
                        </p>
                    </div>
                </div>

                {errors.payment && (
                    <Alert variant="destructive">
                        <AlertTitle>Pembayaran tidak dapat diubah</AlertTitle>
                        <AlertDescription>
                            <p>{errors.payment}</p>
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-6 xl:grid-cols-[1fr_20rem]">
                    <PaymentCard
                        payment={payment}
                        blockedReason={blocked.settle}
                    />

                    <aside className="h-fit rounded-lg border p-4">
                        <h2 className="mb-3 text-sm font-semibold">Pesanan</h2>

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
                                            ORDER_STATUS_TONE[
                                                order.status as OrderStatus
                                            ]
                                        }
                                    />
                                    <StatusBadge
                                        label={`Pembayaran: ${order.payment_status_label}`}
                                        tone={
                                            PAYMENT_STATUS_TONE[
                                                order.payment_status as OrderPaymentStatus
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
                                {payment.branch && (
                                    <div className="flex justify-between gap-3">
                                        <span className="text-muted-foreground">
                                            Cabang
                                        </span>
                                        <span>{payment.branch.name}</span>
                                    </div>
                                )}
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Pesanan tidak dapat dimuat.
                            </p>
                        )}

                        {payment.raw_payload && (
                            <details className="mt-4">
                                <summary className="cursor-pointer text-xs font-semibold text-muted-foreground">
                                    Payload penyedia pembayaran
                                </summary>
                                {/* Kept verbatim so a disputed payment can be
                                    read as the gateway described it, not as we
                                    interpreted it (P2-E1-T6). */}
                                <pre className="mt-2 max-h-72 overflow-auto rounded-md bg-muted p-2 text-[11px] break-all whitespace-pre-wrap">
                                    {JSON.stringify(
                                        payment.raw_payload,
                                        null,
                                        2,
                                    )}
                                </pre>
                            </details>
                        )}
                    </aside>
                </div>
            </div>
        </>
    );
}
