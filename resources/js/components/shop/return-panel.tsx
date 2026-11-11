import { Link, router } from '@inertiajs/react';
import { Banknote, Undo2 } from 'lucide-react';
import { StatusBadge } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import { formatDateTime, formatRupiah } from '@/lib/format';
import account from '@/routes/shop/account';
import {
    REFUND_STATE_TONE,
    RETURN_STATE_TONE,
    type CustomerRefund,
    type CustomerReturn,
} from '@/types/return';

/**
 * Where a customer's return requests stand, on their own order page
 * (P2-E4-T2, T4).
 *
 * The *result*, not the form: asking for a return takes a quantity per line, a
 * reason, a paragraph and photos, which has a screen of its own. This is what
 * the customer comes back to look at — whether the branch agreed, what it
 * said if it did not, and whether the money has arrived.
 *
 * The rejection reason is shown in full, because it is the only thing that
 * tells a customer what to do differently, and the only sentences written into
 * that column are ones meant to be read.
 *
 * Withdrawing is offered only while `can_cancel` says the server would accept
 * it — once the goods are at the branch, calling it off is a conversation.
 */
export function ReturnPanel({
    orderId,
    returns,
    refunds,
    canRequest,
    blockedReason,
}: {
    orderId: number;
    returns: CustomerReturn[];
    refunds: CustomerRefund[];
    canRequest: boolean;
    blockedReason: string | null;
}) {
    if (returns.length === 0 && refunds.length === 0 && !canRequest) {
        return null;
    }

    const cancel = (item: CustomerReturn): void => {
        router.post(
            account.orders.returns.cancel([orderId, item.id]).url,
            {},
            { preserveScroll: true },
        );
    };

    return (
        <section className="rounded-lg border p-4 text-sm">
            <h2 className="mb-3 flex items-center gap-2 font-semibold">
                <Undo2 aria-hidden className="size-4" />
                Retur &amp; pengembalian dana
            </h2>

            {returns.length === 0 ? (
                <p className="text-muted-foreground">
                    Belum ada pengajuan retur untuk pesanan ini.
                </p>
            ) : (
                <ul className="flex flex-col gap-3">
                    {returns.map((item) => (
                        <li
                            key={item.id}
                            className="flex flex-col gap-2 rounded-md border p-3"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusBadge
                                    label={item.status_label}
                                    tone={RETURN_STATE_TONE[item.status]}
                                />
                                <span className="font-medium tabular-nums">
                                    {item.code}
                                </span>
                            </div>

                            <p className="text-xs text-muted-foreground">
                                {item.type_label} · {item.reason_label} ·{' '}
                                {item.item_count} unit · diajukan{' '}
                                {formatDateTime(item.requested_at)}
                            </p>

                            <ul className="text-xs text-muted-foreground">
                                {item.items.map((line) => (
                                    <li key={line.id} className="truncate">
                                        {line.quantity}× {line.display_name}
                                    </li>
                                ))}
                            </ul>

                            {item.rejection_reason && (
                                <p className="rounded-md border border-destructive/40 bg-destructive/5 p-2 text-xs">
                                    <span className="font-medium">
                                        Ditolak:{' '}
                                    </span>
                                    {item.rejection_reason}
                                </p>
                            )}

                            {item.status === 'approved' && (
                                <p className="text-xs text-muted-foreground">
                                    Kirimkan barangnya ke cabang. Status akan
                                    berubah setelah barang diterima dan
                                    diperiksa.
                                </p>
                            )}

                            {item.can_cancel && (
                                <div>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => cancel(item)}
                                    >
                                        Batalkan pengajuan
                                    </Button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {refunds.length > 0 && (
                <div className="mt-4 border-t pt-3">
                    <h3 className="mb-2 flex items-center gap-2 text-xs font-semibold">
                        <Banknote aria-hidden className="size-3.5" />
                        Pengembalian dana
                    </h3>
                    <ul className="flex flex-col gap-2">
                        {refunds.map((refund) => (
                            <li
                                key={refund.id}
                                className="flex flex-wrap items-center justify-between gap-2"
                            >
                                <div className="min-w-0">
                                    <StatusBadge
                                        label={refund.status_label}
                                        tone={REFUND_STATE_TONE[refund.status]}
                                    />
                                    <span className="ms-2 text-xs text-muted-foreground">
                                        {refund.method_label}
                                        {refund.processed_at &&
                                            ` · ${formatDateTime(refund.processed_at)}`}
                                    </span>
                                </div>
                                <span className="font-medium tabular-nums">
                                    {formatRupiah(refund.amount)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="mt-4 border-t pt-3">
                {canRequest ? (
                    <Button variant="outline" size="sm" asChild>
                        <Link href={account.orders.returns.create(orderId).url}>
                            <Undo2 aria-hidden />
                            Ajukan retur
                        </Link>
                    </Button>
                ) : (
                    blockedReason && (
                        <p className="text-xs text-muted-foreground">
                            {blockedReason}
                        </p>
                    )
                )}
            </div>
        </section>
    );
}
