import { useForm } from '@inertiajs/react';
import { Info, Store } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import orders from '@/routes/admin/orders';

/**
 * Handing a Click & Collect order over at the counter (P2-E2-T7).
 *
 * The code is asked for, never shown: it is issued when the goods start
 * waiting, emailed to the customer, and read out at the till. A screen that
 * displayed it to staff would make asking for it pointless.
 *
 * The waiver exists because a customer who has lost their phone is a real
 * counter problem, and a shop with no way past the code is a shop that writes
 * the codes down. It is deliberate, it needs a tick, and it is recorded in the
 * order's timeline with the staff member's name on it.
 */
export function ConfirmPickupForm({
    orderId,
    blockedReason,
}: {
    orderId: number;
    /** Why this order cannot be handed over yet, straight from the server. */
    blockedReason: string | null;
}) {
    const form = useForm<{
        pickup_code: string;
        override_missing_code: boolean;
        note: string;
    }>({
        pickup_code: '',
        override_missing_code: false,
        note: '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(orders.pickup.confirm(orderId).url, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    if (blockedReason !== null) {
        return (
            <Alert>
                <Info aria-hidden />
                <AlertDescription>{blockedReason}</AlertDescription>
            </Alert>
        );
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-3">
            <div className="grid gap-1.5">
                <Label htmlFor="pickup_code">Kode pengambilan</Label>
                <Input
                    id="pickup_code"
                    value={form.data.pickup_code}
                    onChange={(event) =>
                        form.setData('pickup_code', event.target.value)
                    }
                    placeholder="6 karakter dari email pelanggan"
                    autoComplete="off"
                    maxLength={20}
                    disabled={form.data.override_missing_code}
                    className="font-mono tracking-widest uppercase"
                />
                <p className="text-xs text-muted-foreground">
                    Minta pelanggan menunjukkan kode dari emailnya atau dari
                    halaman pesanannya.
                </p>
            </div>

            <div className="flex items-start gap-2">
                <Checkbox
                    id="override_missing_code"
                    checked={form.data.override_missing_code}
                    onCheckedChange={(checked) =>
                        form.setData('override_missing_code', checked === true)
                    }
                />
                <Label
                    htmlFor="override_missing_code"
                    className="text-sm leading-snug font-normal"
                >
                    Pelanggan tidak dapat menunjukkan kodenya — serahkan atas
                    tanggung jawab saya. Keputusan ini tercatat di riwayat
                    pesanan.
                </Label>
            </div>

            {form.data.override_missing_code && (
                <div className="grid gap-1.5">
                    <Label htmlFor="pickup_note">
                        Alasan / bukti identitas
                    </Label>
                    <Input
                        id="pickup_note"
                        value={form.data.note}
                        onChange={(event) =>
                            form.setData('note', event.target.value)
                        }
                        placeholder="Mis. KTP diperiksa, nama sesuai pesanan"
                    />
                </div>
            )}

            {form.errors.pickup_code && (
                <p className="text-sm text-destructive">
                    {form.errors.pickup_code}
                </p>
            )}

            <Button
                type="submit"
                size="sm"
                className="self-start"
                disabled={form.processing}
            >
                <Store aria-hidden />
                {form.processing ? 'Menyimpan…' : 'Konfirmasi diambil'}
            </Button>
        </form>
    );
}
