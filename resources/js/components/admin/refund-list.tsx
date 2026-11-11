import { useForm } from '@inertiajs/react';
import { Banknote, Check, X } from 'lucide-react';
import { useState } from 'react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime, formatRupiah } from '@/lib/format';
import refundRoutes from '@/routes/admin/refunds';
import { REFUND_STATE_TONE, type RefundRow } from '@/types/return';

/**
 * What has gone back against an order, and what has not yet (P2-E4-T4).
 *
 * A refund opened for a bank transfer is `pending` until somebody has actually
 * made the transfer, so the two buttons here are the other half of that: one
 * records the reference and settles it, the other records that it bounced. The
 * amount of a failed refund goes straight back into what the order may still
 * give back, which is why failing one is safe to offer.
 *
 * `canProcess` comes from the server (`RefundPolicy::process`, central only);
 * a branch reads this list without its buttons.
 */
export function RefundList({
    refunds,
    canProcess = false,
}: {
    refunds: RefundRow[];
    canProcess?: boolean;
}) {
    const [settling, setSettling] = useState<RefundRow | null>(null);
    const [failing, setFailing] = useState<RefundRow | null>(null);

    if (refunds.length === 0) {
        return (
            <section className="rounded-lg border border-dashed p-4">
                <h2 className="flex items-center gap-2 text-sm font-semibold">
                    <Banknote aria-hidden className="size-4" />
                    Pengembalian dana
                </h2>
                <p className="mt-1 text-xs text-muted-foreground">
                    Belum ada dana yang dikembalikan untuk pesanan ini.
                </p>
            </section>
        );
    }

    return (
        <section className="flex flex-col gap-3 rounded-lg border p-4">
            <h2 className="flex items-center gap-2 text-sm font-semibold">
                <Banknote aria-hidden className="size-4" />
                Pengembalian dana
            </h2>

            <ul className="divide-y rounded-md border">
                {refunds.map((refund) => (
                    <li key={refund.id} className="flex flex-col gap-2 p-3">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <StatusBadge
                                        label={refund.status_label}
                                        tone={REFUND_STATE_TONE[refund.status]}
                                    />
                                    <span className="text-sm font-medium">
                                        {refund.method_label}
                                    </span>
                                </div>
                                <p className="mt-1 font-mono text-xs break-all text-muted-foreground">
                                    {refund.code}
                                    {refund.reference &&
                                        ` · ${refund.reference}`}
                                </p>
                                {refund.account_info && (
                                    <p className="text-xs text-muted-foreground">
                                        Ke: {refund.account_info}
                                    </p>
                                )}
                                {refund.notes && (
                                    <p className="text-xs text-muted-foreground">
                                        {refund.notes}
                                    </p>
                                )}
                                {refund.failure_reason && (
                                    <p className="text-xs text-destructive">
                                        {refund.failure_reason}
                                    </p>
                                )}
                                <p className="text-xs text-muted-foreground">
                                    {refund.processed_at
                                        ? `${formatDateTime(refund.processed_at)}${
                                              refund.processed_by
                                                  ? ` · ${refund.processed_by}`
                                                  : ''
                                          }`
                                        : `Dibuat ${formatDateTime(refund.created_at)}`}
                                </p>
                            </div>

                            <span className="text-sm font-semibold tabular-nums">
                                {formatRupiah(refund.amount)}
                            </span>
                        </div>

                        {canProcess && refund.status === 'pending' && (
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    size="sm"
                                    onClick={() => setSettling(refund)}
                                >
                                    <Check aria-hidden />
                                    Tandai sudah dikembalikan
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setFailing(refund)}
                                >
                                    <X aria-hidden />
                                    Tandai gagal
                                </Button>
                            </div>
                        )}
                    </li>
                ))}
            </ul>

            {settling && (
                <SettleDialog
                    refund={settling}
                    onClose={() => setSettling(null)}
                />
            )}
            {failing && (
                <FailDialog refund={failing} onClose={() => setFailing(null)} />
            )}
        </section>
    );
}

function SettleDialog({
    refund,
    onClose,
}: {
    refund: RefundRow;
    onClose: () => void;
}) {
    const form = useForm<{ reference: string; notes: string }>({
        reference: refund.reference ?? '',
        notes: '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(refundRoutes.settle(refund.id).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>
                            Tandai {refund.code} sudah dikembalikan?
                        </DialogTitle>
                        <DialogDescription>
                            Ini mencatat bahwa dana{' '}
                            {formatRupiah(refund.amount)} benar-benar sudah
                            dikirim, dan memperbarui status pembayaran
                            pesanannya. Lakukan hanya setelah transfernya
                            dibuat.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="flex flex-col gap-3 py-4">
                        <div>
                            <Label htmlFor={`settle-reference-${refund.id}`}>
                                Nomor referensi
                            </Label>
                            <Input
                                id={`settle-reference-${refund.id}`}
                                className="mt-2"
                                maxLength={255}
                                placeholder="Mis. TRF20261111001"
                                value={form.data.reference}
                                onChange={(event) =>
                                    form.setData(
                                        'reference',
                                        event.target.value,
                                    )
                                }
                            />
                            {form.errors.reference && (
                                <p className="mt-2 text-sm text-destructive">
                                    {form.errors.reference}
                                </p>
                            )}
                        </div>

                        <div>
                            <Label htmlFor={`settle-notes-${refund.id}`}>
                                Catatan
                            </Label>
                            <Textarea
                                id={`settle-notes-${refund.id}`}
                                className="mt-2"
                                rows={2}
                                maxLength={1000}
                                value={form.data.notes}
                                onChange={(event) =>
                                    form.setData('notes', event.target.value)
                                }
                            />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Batal
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing
                                ? 'Menyimpan…'
                                : 'Tandai sudah dikembalikan'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function FailDialog({
    refund,
    onClose,
}: {
    refund: RefundRow;
    onClose: () => void;
}) {
    const form = useForm<{ reason: string }>({ reason: '' });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(refundRoutes.fail(refund.id).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Tandai {refund.code} gagal?</DialogTitle>
                        <DialogDescription>
                            Nominalnya kembali ke sisa dana yang masih dapat
                            dikembalikan, jadi refund baru dapat dibuat dengan
                            rekening yang benar.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="py-4">
                        <Label htmlFor={`fail-reason-${refund.id}`}>
                            Alasan kegagalan
                        </Label>
                        <Textarea
                            id={`fail-reason-${refund.id}`}
                            className="mt-2"
                            rows={3}
                            maxLength={255}
                            placeholder="Mis. nomor rekening tujuan tidak ditemukan."
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
                            onClick={onClose}
                        >
                            Batal
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing}
                        >
                            {form.processing ? 'Menyimpan…' : 'Tandai gagal'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
