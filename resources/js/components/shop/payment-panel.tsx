import { useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Clock,
    Copy,
    ExternalLink,
    FileText,
    Upload,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { PaymentMethodPicker } from '@/components/shop/payment-method-picker';
import { StatusBadge } from '@/components/panel/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { formatDateTime, formatRupiah } from '@/lib/format';
import account from '@/routes/shop/account';
import { PAYMENT_STATE_TONE } from '@/types/payment';
import type { CustomerPayment, PaymentMethodChoice } from '@/types/payment';

/**
 * Everything a customer needs in order to pay, on the page that already shows
 * them the order (P2-E1-T3).
 *
 * Four states, and they are genuinely different screens rather than variations
 * of one:
 *
 * 1. **Nothing started**, or the last attempt failed — the method picker, so
 *    "pay" and "pay a different way" are the same control.
 * 2. **Waiting on the customer** — the instructions: the account to transfer
 *    into, or the number, code or link the gateway issued, plus the deadline.
 * 3. **Waiting on the shop** — a receipt has been uploaded and somebody has to
 *    look at it. Nothing to do but wait, and the panel says so rather than
 *    leaving the upload form sitting there inviting a second copy.
 * 4. **Settled** — a short confirmation, because the order's own status badge
 *    is already carrying the news.
 *
 * The countdown is computed in the browser from `expires_at` and ticks every
 * second. It is a **reminder, not a rule**: what actually decides is the
 * server's sweep (ADR-041), and the panel reloads nothing when it hits zero —
 * a page that reset itself under someone mid-transfer would be worse than one
 * showing a stale "00:00" until they act.
 */
export function PaymentPanel({
    orderId,
    payment,
    methods,
    blockedReason,
    canPay,
    history,
}: {
    orderId: number;
    payment: CustomerPayment | null;
    methods: PaymentMethodChoice[];
    blockedReason: string | null;
    canPay: boolean;
    history: CustomerPayment[];
}) {
    if (payment !== null && payment.status === 'success') {
        return (
            <section className="rounded-lg border border-emerald-600/40 bg-emerald-600/5 p-4">
                <h2 className="flex items-center gap-2 text-sm font-semibold">
                    <CheckCircle2 aria-hidden className="size-4" />
                    Pembayaran diterima
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    {payment.method_name} · {formatRupiah(payment.amount)}
                    {payment.paid_at && ` · ${formatDateTime(payment.paid_at)}`}
                </p>
            </section>
        );
    }

    return (
        <section className="rounded-lg border p-4">
            <h2 className="mb-3 text-sm font-semibold">Pembayaran</h2>

            {payment !== null && payment.status === 'pending' ? (
                <PendingPayment orderId={orderId} payment={payment} />
            ) : canPay ? (
                <StartPayment
                    orderId={orderId}
                    methods={methods}
                    lastFailure={payment}
                />
            ) : (
                <p className="text-sm text-muted-foreground">
                    {blockedReason ??
                        'Pesanan ini tidak menunggu pembayaran lagi.'}
                </p>
            )}

            {history.length > 0 && <PaymentHistory entries={history} />}
        </section>
    );
}

/**
 * The method picker, plus the reason the previous attempt came to nothing.
 *
 * The reason is shown above the picker rather than inside it: it is about what
 * happened, and the choice below is about what to do next.
 */
function StartPayment({
    orderId,
    methods,
    lastFailure,
}: {
    orderId: number;
    methods: PaymentMethodChoice[];
    lastFailure: CustomerPayment | null;
}) {
    const eligible = methods.find((method) => method.is_eligible)?.id ?? null;
    const form = useForm<{ payment_method_id: number | null }>({
        payment_method_id: eligible,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(account.orders.payments.store(orderId).url, {
            preserveScroll: true,
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-3">
            {lastFailure !== null && (
                <Alert variant="destructive">
                    <AlertTriangle aria-hidden />
                    <AlertTitle>
                        Pembayaran sebelumnya{' '}
                        {lastFailure.status_label.toLowerCase()}
                    </AlertTitle>
                    <AlertDescription>
                        <p>
                            {lastFailure.failure_reason ??
                                'Silakan pilih metode pembayaran dan coba lagi.'}
                        </p>
                    </AlertDescription>
                </Alert>
            )}

            <PaymentMethodPicker
                methods={methods}
                selectedId={form.data.payment_method_id}
                onSelect={(id) => form.setData('payment_method_id', id)}
                error={form.errors.payment_method_id}
            />

            {methods.length > 0 && (
                <Button
                    type="submit"
                    disabled={
                        form.processing || form.data.payment_method_id === null
                    }
                >
                    {form.processing ? 'Memproses…' : 'Lanjutkan pembayaran'}
                </Button>
            )}
        </form>
    );
}

/**
 * The instructions for the attempt in progress.
 */
function PendingPayment({
    orderId,
    payment,
}: {
    orderId: number;
    payment: CustomerPayment;
}) {
    const waitingOnShop = payment.has_proof;

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center gap-2">
                <StatusBadge
                    label={payment.status_label}
                    tone={PAYMENT_STATE_TONE[payment.status]}
                />
                <span className="text-sm text-muted-foreground">
                    {payment.method_name}
                </span>
                <span className="ms-auto text-sm font-semibold tabular-nums">
                    {formatRupiah(payment.amount)}
                </span>
            </div>

            {payment.fee_amount > 0 && (
                <p className="text-xs text-muted-foreground">
                    Termasuk biaya metode pembayaran{' '}
                    {formatRupiah(payment.fee_amount)}.
                </p>
            )}

            <PaymentDeadline payment={payment} />

            {payment.bank_account && (
                <dl className="rounded-lg border bg-muted/40 p-3 text-sm">
                    <div className="flex items-center justify-between gap-3">
                        <dt className="text-muted-foreground">
                            {payment.bank_account.name}
                        </dt>
                        <dd className="flex items-center gap-2">
                            <span className="font-semibold tabular-nums">
                                {payment.bank_account.account_number}
                            </span>
                            <CopyButton
                                value={payment.bank_account.account_number}
                                label="Salin nomor rekening"
                            />
                        </dd>
                    </div>
                    <div className="mt-1 flex items-center justify-between gap-3">
                        <dt className="text-muted-foreground">Atas nama</dt>
                        <dd>{payment.bank_account.account_holder}</dd>
                    </div>
                </dl>
            )}

            {payment.va_number && (
                <dl className="rounded-lg border bg-muted/40 p-3 text-sm">
                    <div className="flex items-center justify-between gap-3">
                        <dt className="text-muted-foreground">
                            Virtual Account
                        </dt>
                        <dd className="flex items-center gap-2">
                            <span className="font-semibold tabular-nums">
                                {payment.va_number}
                            </span>
                            <CopyButton
                                value={payment.va_number}
                                label="Salin nomor virtual account"
                            />
                        </dd>
                    </div>
                </dl>
            )}

            {payment.instructions && (
                <div className="text-sm whitespace-pre-line text-muted-foreground">
                    {payment.instructions}
                </div>
            )}

            {payment.payment_url && (
                <Button asChild>
                    {/* An external payment page, so a plain anchor rather than
                        an Inertia link — and `noopener` because the page is
                        somebody else's. */}
                    <a
                        href={payment.payment_url}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <ExternalLink aria-hidden />
                        Buka halaman pembayaran
                    </a>
                </Button>
            )}

            {waitingOnShop ? (
                <Alert>
                    <FileText aria-hidden />
                    <AlertTitle>Bukti transfer sudah kami terima</AlertTitle>
                    <AlertDescription>
                        <p>
                            Diunggah {formatDateTime(payment.proof_uploaded_at)}
                            . Cabang akan memeriksanya dan mengonfirmasi
                            pembayaran Anda.
                        </p>
                    </AlertDescription>
                </Alert>
            ) : null}

            {payment.accepts_proof && (
                <ProofUploadForm
                    orderId={orderId}
                    payment={payment}
                    replacing={waitingOnShop}
                />
            )}
        </div>
    );
}

/**
 * How long is left, counted down in the browser.
 */
function PaymentDeadline({ payment }: { payment: CustomerPayment }) {
    const remaining = useCountdown(payment.expires_at);

    if (payment.expires_at === null) {
        return null;
    }

    if (payment.has_overrun || remaining === 0) {
        return (
            <Alert variant="destructive">
                <AlertTriangle aria-hidden />
                <AlertTitle>Batas waktu pembayaran terlewat</AlertTitle>
                <AlertDescription>
                    <p>
                        Stok yang ditahan untuk pesanan ini dilepas kembali.
                        Hubungi cabang bila dana sudah Anda kirim.
                    </p>
                </AlertDescription>
            </Alert>
        );
    }

    return (
        <p className="flex items-center gap-2 text-sm">
            <Clock aria-hidden className="size-4 text-muted-foreground" />
            <span>
                Bayar sebelum{' '}
                <strong className="tabular-nums">
                    {formatDateTime(payment.expires_at)}
                </strong>
                {remaining !== null && (
                    <span className="text-muted-foreground">
                        {' '}
                        · sisa {formatCountdown(remaining)}
                    </span>
                )}
            </span>
        </p>
    );
}

function ProofUploadForm({
    orderId,
    payment,
    replacing,
}: {
    orderId: number;
    payment: CustomerPayment;
    replacing: boolean;
}) {
    const form = useForm<{ proof: File | null; note: string }>({
        proof: null,
        note: payment.proof_note ?? '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(account.orders.payments.proof([orderId, payment.id]).url, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => form.setData('proof', null),
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-3 border-t pt-4">
            <div>
                <Label htmlFor="proof">
                    {replacing
                        ? 'Ganti bukti transfer'
                        : 'Unggah bukti transfer'}
                </Label>
                <Input
                    id="proof"
                    type="file"
                    className="mt-2"
                    accept="image/jpeg,image/png,image/webp,application/pdf"
                    onChange={(event) =>
                        form.setData('proof', event.target.files?.[0] ?? null)
                    }
                />
                <p className="mt-1 text-xs text-muted-foreground">
                    JPG, PNG, WEBP, atau PDF, maksimal 4 MB.
                </p>
                {form.errors.proof && (
                    <p className="mt-1 text-sm text-destructive">
                        {form.errors.proof}
                    </p>
                )}
            </div>

            <div>
                <Label htmlFor="proof-note">Catatan pengirim (opsional)</Label>
                <Input
                    id="proof-note"
                    className="mt-2"
                    maxLength={255}
                    placeholder="Mis. transfer dari BCA a/n Budi Santoso"
                    value={form.data.note}
                    onChange={(event) =>
                        form.setData('note', event.target.value)
                    }
                />
                {form.errors.note && (
                    <p className="mt-1 text-sm text-destructive">
                        {form.errors.note}
                    </p>
                )}
            </div>

            <Button
                type="submit"
                variant={replacing ? 'outline' : 'default'}
                disabled={form.processing || form.data.proof === null}
            >
                <Upload aria-hidden />
                {form.processing ? 'Mengunggah…' : 'Kirim bukti transfer'}
            </Button>
        </form>
    );
}

/**
 * The attempts that came before — the virtual account that lapsed before the
 * transfer that worked.
 */
function PaymentHistory({ entries }: { entries: CustomerPayment[] }) {
    return (
        <>
            <Separator className="my-4" />
            <h3 className="mb-2 text-xs font-semibold text-muted-foreground">
                Riwayat percobaan pembayaran
            </h3>
            <ul className="flex flex-col gap-2 text-sm">
                {entries.map((entry) => (
                    <li
                        key={entry.id}
                        className="flex flex-wrap items-center gap-2"
                    >
                        <StatusBadge
                            label={entry.status_label}
                            tone={PAYMENT_STATE_TONE[entry.status]}
                        />
                        <span className="text-muted-foreground">
                            {entry.method_name} ·{' '}
                            {formatDateTime(entry.created_at)}
                        </span>
                        {entry.failure_reason && (
                            <span className="w-full text-xs text-muted-foreground">
                                {entry.failure_reason}
                            </span>
                        )}
                    </li>
                ))}
            </ul>
        </>
    );
}

/**
 * Copy a figure a customer has to retype into a banking app.
 *
 * `navigator.clipboard` is unavailable on an insecure origin and may be
 * refused by the browser, so the failure is swallowed: the number is on screen
 * either way, and an error toast about a convenience would be noise.
 */
function CopyButton({ value, label }: { value: string; label: string }) {
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        if (!copied) {
            return;
        }

        const timer = window.setTimeout(() => setCopied(false), 2000);

        return () => window.clearTimeout(timer);
    }, [copied]);

    const copy = () => {
        void navigator.clipboard
            ?.writeText(value)
            .then(() => setCopied(true))
            .catch(() => undefined);
    };

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            className="size-7"
            onClick={copy}
            aria-label={label}
        >
            {copied ? (
                <CheckCircle2 aria-hidden className="size-3.5" />
            ) : (
                <Copy aria-hidden className="size-3.5" />
            )}
        </Button>
    );
}

/**
 * Seconds left until `deadline`, ticking once a second, or null when there is
 * no deadline.
 */
function useCountdown(deadline: string | null): number | null {
    const [remaining, setRemaining] = useState<number | null>(() =>
        secondsUntil(deadline),
    );

    useEffect(() => {
        setRemaining(secondsUntil(deadline));

        if (deadline === null) {
            return;
        }

        const timer = window.setInterval(() => {
            setRemaining(secondsUntil(deadline));
        }, 1000);

        return () => window.clearInterval(timer);
    }, [deadline]);

    return remaining;
}

function secondsUntil(deadline: string | null): number | null {
    if (deadline === null) {
        return null;
    }

    const left = Math.floor((Date.parse(deadline) - Date.now()) / 1000);

    return Number.isNaN(left) ? null : Math.max(0, left);
}

function formatCountdown(seconds: number): string {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const rest = seconds % 60;

    const pad = (value: number) => String(value).padStart(2, '0');

    return hours > 0
        ? `${hours}:${pad(minutes)}:${pad(rest)}`
        : `${pad(minutes)}:${pad(rest)}`;
}
