import { useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    Clock,
    ExternalLink,
    FileText,
    X,
} from 'lucide-react';
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
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime, formatRupiah } from '@/lib/format';
import payments from '@/routes/admin/payments';
import { PAYMENT_STATE_TONE } from '@/types/payment';
import type { PaymentDetail } from '@/types/payment';

/**
 * One payment attempt, with whatever may still be done about it (P2-E1-T4).
 *
 * Shown twice: on the payment queue's detail screen, and on the order detail
 * screen's payment section. The same component in both so a staff member who
 * confirms money from the order they were looking at goes through exactly the
 * decision they would have gone through from the queue.
 *
 * Three buttons, and which of them appear is the **server's** decision
 * (`can.settle`, built from `PaymentPolicy` and
 * `Payment::verificationBlockedReason()`), never this component's: a button
 * offered for something the server will refuse is worse than no button.
 *
 * Rejecting opens a dialog because its reason is required and is **read by the
 * customer** — the one field on this screen whose audience is not the person
 * typing it, which the dialog says out loud.
 */
export function PaymentCard({
    payment,
    blockedReason,
}: {
    payment: PaymentDetail;
    blockedReason?: string | null;
}) {
    const [rejecting, setRejecting] = useState(false);

    const verifyForm = useForm<{ note: string }>({ note: '' });
    const expireForm = useForm({});
    const canSettle = payment.can.settle ?? false;

    const submitVerify = () => {
        verifyForm.post(payments.verify(payment.id).url, {
            preserveScroll: true,
            onSuccess: () => verifyForm.reset(),
        });
    };

    const submitExpire = () => {
        expireForm.post(payments.expire(payment.id).url, {
            preserveScroll: true,
        });
    };

    return (
        <div className="flex flex-col gap-4 rounded-lg border p-4">
            <div className="flex flex-wrap items-start gap-3">
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusBadge
                            label={payment.status_label}
                            tone={PAYMENT_STATE_TONE[payment.status]}
                        />
                        <span className="text-sm font-medium">
                            {payment.method_name}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {payment.method_type_label}
                        </span>
                    </div>
                    <p className="mt-1 font-mono text-xs break-all text-muted-foreground">
                        {payment.reference}
                        {payment.external_id && ` · ${payment.external_id}`}
                    </p>
                </div>

                <div className="text-right">
                    <p className="font-semibold tabular-nums">
                        {formatRupiah(payment.amount)}
                    </p>
                    {payment.fee_amount > 0 && (
                        <p className="text-xs text-muted-foreground">
                            termasuk biaya {formatRupiah(payment.fee_amount)}
                        </p>
                    )}
                </div>
            </div>

            <dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                <Row
                    label="Dibuat"
                    value={formatDateTime(payment.created_at)}
                />
                <Row
                    label="Batas waktu"
                    value={
                        payment.expires_at
                            ? formatDateTime(payment.expires_at)
                            : 'Tanpa batas'
                    }
                />
                {payment.paid_at && (
                    <Row
                        label="Lunas"
                        value={formatDateTime(payment.paid_at)}
                    />
                )}
                {payment.verified_at && (
                    <Row
                        label="Diputuskan"
                        value={`${formatDateTime(payment.verified_at)}${
                            payment.verified_by
                                ? ` oleh ${payment.verified_by}`
                                : ' otomatis'
                        }`}
                    />
                )}
                {payment.va_number && (
                    <Row label="Virtual Account" value={payment.va_number} />
                )}
                {payment.bank_account && (
                    <Row
                        label="Rekening tujuan"
                        value={`${payment.bank_account.name} ${payment.bank_account.account_number} (${payment.bank_account.account_holder})`}
                    />
                )}
            </dl>

            {payment.has_overrun && (
                <p className="flex items-center gap-2 text-sm text-destructive">
                    <AlertTriangle aria-hidden className="size-4" />
                    Batas waktu pembayaran sudah terlewat.
                </p>
            )}

            {payment.failure_reason && (
                <div className="rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm">
                    <p className="font-medium">Alasan</p>
                    <p className="text-muted-foreground">
                        {payment.failure_reason}
                    </p>
                </div>
            )}

            {payment.proof_url ? (
                <div className="rounded-md border p-3">
                    <p className="mb-2 flex items-center gap-2 text-sm font-medium">
                        <FileText aria-hidden className="size-4" />
                        Bukti transfer
                        {payment.proof_uploaded_at && (
                            <span className="font-normal text-muted-foreground">
                                · {formatDateTime(payment.proof_uploaded_at)}
                            </span>
                        )}
                    </p>

                    {payment.proof_note && (
                        <p className="mb-2 text-sm text-muted-foreground">
                            {payment.proof_note}
                        </p>
                    )}

                    {/* The uploaded file may be a PDF, which an `img` cannot
                        render, so the thumbnail is attempted and the link is
                        always there. */}
                    <a
                        href={payment.proof_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex flex-col gap-2"
                    >
                        <img
                            src={payment.proof_url}
                            alt="Bukti transfer yang diunggah pelanggan"
                            className="max-h-64 rounded-md border object-contain"
                            onError={(event) => {
                                event.currentTarget.style.display = 'none';
                            }}
                        />
                        <span className="inline-flex items-center gap-1 text-sm text-primary hover:underline">
                            <ExternalLink aria-hidden className="size-3.5" />
                            Buka berkas asli
                        </span>
                    </a>
                </div>
            ) : (
                payment.status === 'pending' &&
                payment.method_type === 'bank_transfer' && (
                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Clock aria-hidden className="size-4" />
                        Pelanggan belum mengunggah bukti transfer.
                    </p>
                )
            )}

            {canSettle ? (
                <div className="flex flex-wrap gap-2 border-t pt-3">
                    <Button
                        onClick={submitVerify}
                        disabled={verifyForm.processing}
                    >
                        <Check aria-hidden />
                        {verifyForm.processing
                            ? 'Mengonfirmasi…'
                            : 'Konfirmasi lunas'}
                    </Button>
                    <Button
                        variant="outline"
                        onClick={() => setRejecting(true)}
                    >
                        <X aria-hidden />
                        Tolak
                    </Button>
                    <Button
                        variant="ghost"
                        onClick={submitExpire}
                        disabled={expireForm.processing}
                    >
                        <Clock aria-hidden />
                        Tandai kedaluwarsa
                    </Button>
                </div>
            ) : (
                blockedReason && (
                    <p className="border-t pt-3 text-xs text-muted-foreground">
                        {blockedReason}
                    </p>
                )
            )}

            <RejectDialog
                payment={payment}
                open={rejecting}
                onOpenChange={setRejecting}
            />
        </div>
    );
}

function RejectDialog({
    payment,
    open,
    onOpenChange,
}: {
    payment: PaymentDetail;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm<{ reason: string }>({ reason: '' });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(payments.reject(payment.id).url, {
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
                        <DialogTitle>Tolak pembayaran ini?</DialogTitle>
                        <DialogDescription>
                            Pesanan tetap bisa dibayar: pelanggan dapat
                            mengunggah bukti lain atau memilih metode lain
                            selama batas waktunya belum lewat. Alasan di bawah
                            <strong> dibaca pelanggan</strong>, jadi tulis apa
                            yang harus mereka perbaiki.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="py-4">
                        <Label htmlFor={`reject-reason-${payment.id}`}>
                            Alasan penolakan
                        </Label>
                        <Textarea
                            id={`reject-reason-${payment.id}`}
                            className="mt-2"
                            rows={3}
                            maxLength={255}
                            placeholder="Mis. nominal transfer kurang Rp5.000 dari total pesanan."
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
                            {form.processing ? 'Menolak…' : 'Tolak pembayaran'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex justify-between gap-3 sm:block">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right sm:text-left">{value}</dd>
        </div>
    );
}
