import { useForm } from '@inertiajs/react';
import { Banknote } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
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
import { formatRupiah } from '@/lib/format';
import orders from '@/routes/admin/orders';
import type { PaymentRow } from '@/types/payment';
import type { RefundMethod, RefundMethodChoice } from '@/types/return';

export type RefundFormOptions = {
    methods: RefundMethodChoice[];
    payments: PaymentRow[];
    refundable_amount: number;
    suggested_amount: number;
};

/**
 * Sending money back (P2-E4-T4).
 *
 * Shown on the return's detail screen and on the order's, because the person
 * deciding a refund is reading one or the other. The same component in both so
 * the decision is identical whichever screen it was reached from.
 *
 * Three things the form is built around:
 *
 * - **The amount starts at a suggestion, not a fixed figure.** The server
 *   works out the returned goods plus the delivery when the shop was at fault,
 *   capped at what the order has left to give back, and a staff member may
 *   change it. `ProcessRefund` re-checks the ceiling under the order's row
 *   lock, so the field can never overdraw the order.
 * - **The reference means "I have already sent this".** Filling it settles the
 *   refund at once; leaving it blank opens it as pending, which is what the
 *   refund queue is for. The label says so rather than leaving it to be
 *   discovered.
 * - **A gateway refund needs the attempt the money came in on**, because a
 *   provider will only reverse a transaction it knows about. The select lists
 *   only settled attempts.
 */
export function ProcessRefundForm({
    orderId,
    returnId,
    options,
    blockedReason,
}: {
    orderId: number;
    returnId?: number | null;
    options: RefundFormOptions;
    blockedReason?: string | null;
}) {
    const available = options.methods.filter((method) => method.is_available);
    const [method, setMethod] = useState<RefundMethod>(
        available[0]?.value ?? 'transfer',
    );

    const form = useForm<{
        amount: number | string;
        method: RefundMethod;
        return_id: number | null;
        payment_id: number | null;
        account_info: string;
        reference: string;
        notes: string;
    }>({
        amount: options.suggested_amount,
        method: available[0]?.value ?? 'transfer',
        return_id: returnId ?? null,
        payment_id: options.payments[0]?.id ?? null,
        account_info: '',
        reference: '',
        notes: '',
    });

    const selected = options.methods.find((entry) => entry.value === method);

    const changeMethod = (value: RefundMethod): void => {
        setMethod(value);
        form.setData('method', value);
    };

    const submit = (event: React.FormEvent): void => {
        event.preventDefault();
        form.post(orders.refunds.store(orderId).url, { preserveScroll: true });
    };

    if (blockedReason) {
        return (
            <div className="rounded-lg border border-dashed p-4">
                <h2 className="flex items-center gap-2 text-sm font-semibold">
                    <Banknote aria-hidden className="size-4" />
                    Pengembalian dana
                </h2>
                <p className="mt-1 text-xs text-muted-foreground">
                    {blockedReason}
                </p>
            </div>
        );
    }

    if (options.refundable_amount <= 0) {
        return (
            <div className="rounded-lg border border-dashed p-4">
                <h2 className="flex items-center gap-2 text-sm font-semibold">
                    <Banknote aria-hidden className="size-4" />
                    Pengembalian dana
                </h2>
                <p className="mt-1 text-xs text-muted-foreground">
                    Seluruh dana pesanan ini sudah dikembalikan.
                </p>
            </div>
        );
    }

    return (
        <form
            onSubmit={submit}
            className="flex flex-col gap-4 rounded-lg border p-4"
        >
            <div>
                <h2 className="flex items-center gap-2 text-sm font-semibold">
                    <Banknote aria-hidden className="size-4" />
                    Kembalikan dana
                </h2>
                <p className="mt-1 text-xs text-muted-foreground">
                    Sisa dana yang masih dapat dikembalikan:{' '}
                    <strong className="tabular-nums">
                        {formatRupiah(options.refundable_amount)}
                    </strong>
                </p>
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                <div>
                    <Label htmlFor="refund-amount">Nominal pengembalian</Label>
                    <Input
                        id="refund-amount"
                        type="number"
                        inputMode="numeric"
                        min={1}
                        max={options.refundable_amount}
                        className="mt-1 tabular-nums"
                        value={form.data.amount}
                        onChange={(event) =>
                            form.setData('amount', event.target.value)
                        }
                    />
                    {form.errors.amount && (
                        <p className="mt-1 text-sm text-destructive">
                            {form.errors.amount}
                        </p>
                    )}
                </div>

                <div>
                    <Label htmlFor="refund-method">Cara pengembalian</Label>
                    <Select
                        value={method}
                        onValueChange={(value) =>
                            changeMethod(value as RefundMethod)
                        }
                    >
                        <SelectTrigger id="refund-method" className="mt-1">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {options.methods.map((entry) => (
                                <SelectItem
                                    key={entry.value}
                                    value={entry.value}
                                    disabled={!entry.is_available}
                                >
                                    {entry.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {selected && !selected.is_available && (
                        <p className="mt-1 text-xs text-muted-foreground">
                            {selected.unavailable_reason}
                        </p>
                    )}
                    {form.errors.method && (
                        <p className="mt-1 text-sm text-destructive">
                            {form.errors.method}
                        </p>
                    )}
                </div>
            </div>

            {selected?.is_automatic && (
                <div>
                    <Label htmlFor="refund-payment">Pembayaran asal</Label>
                    {options.payments.length === 0 ? (
                        <p className="mt-1 text-xs text-muted-foreground">
                            Pesanan ini belum punya pembayaran berhasil yang
                            bisa dikembalikan lewat payment gateway. Pilih
                            transfer bank atau tunai.
                        </p>
                    ) : (
                        <Select
                            value={
                                form.data.payment_id === null
                                    ? undefined
                                    : String(form.data.payment_id)
                            }
                            onValueChange={(value) =>
                                form.setData('payment_id', Number(value))
                            }
                        >
                            <SelectTrigger id="refund-payment" className="mt-1">
                                <SelectValue placeholder="Pilih pembayaran" />
                            </SelectTrigger>
                            <SelectContent>
                                {options.payments.map((payment) => (
                                    <SelectItem
                                        key={payment.id}
                                        value={String(payment.id)}
                                    >
                                        {payment.method_name} ·{' '}
                                        {formatRupiah(payment.amount)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                    {form.errors.payment_id && (
                        <p className="mt-1 text-sm text-destructive">
                            {form.errors.payment_id}
                        </p>
                    )}
                </div>
            )}

            {selected?.needs_account_info && (
                <div>
                    <Label htmlFor="refund-account">Rekening tujuan</Label>
                    <Input
                        id="refund-account"
                        className="mt-1"
                        maxLength={500}
                        placeholder="Mis. BCA 1234567890 a/n Budi Santoso"
                        value={form.data.account_info}
                        onChange={(event) =>
                            form.setData('account_info', event.target.value)
                        }
                    />
                    {form.errors.account_info && (
                        <p className="mt-1 text-sm text-destructive">
                            {form.errors.account_info}
                        </p>
                    )}
                </div>
            )}

            {!selected?.is_automatic && (
                <div>
                    <Label htmlFor="refund-reference">
                        Nomor referensi{' '}
                        <span className="font-normal text-muted-foreground">
                            — isi hanya bila dananya sudah Anda kirim
                        </span>
                    </Label>
                    <Input
                        id="refund-reference"
                        className="mt-1"
                        maxLength={255}
                        placeholder="Mis. TRF20261111001"
                        value={form.data.reference}
                        onChange={(event) =>
                            form.setData('reference', event.target.value)
                        }
                    />
                    <p className="mt-1 text-xs text-muted-foreground">
                        Dibiarkan kosong: refund dicatat sebagai &ldquo;menunggu
                        diproses&rdquo; dan masuk daftar Refund untuk ditandai
                        setelah transfernya dibuat.
                    </p>
                    {form.errors.reference && (
                        <p className="mt-1 text-sm text-destructive">
                            {form.errors.reference}
                        </p>
                    )}
                </div>
            )}

            <div>
                <Label htmlFor="refund-notes">Catatan</Label>
                <Textarea
                    id="refund-notes"
                    className="mt-1"
                    rows={2}
                    maxLength={1000}
                    placeholder="Mis. dipotong biaya pengembalian barang Rp10.000."
                    value={form.data.notes}
                    onChange={(event) =>
                        form.setData('notes', event.target.value)
                    }
                />
            </div>

            {form.errors.return_id && (
                <p className="text-sm text-destructive">
                    {form.errors.return_id}
                </p>
            )}

            <div className="border-t pt-3">
                <Button type="submit" disabled={form.processing}>
                    <Banknote aria-hidden />
                    {form.processing ? 'Memproses…' : 'Kembalikan dana'}
                </Button>
            </div>
        </form>
    );
}
