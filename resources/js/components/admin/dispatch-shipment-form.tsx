import { useForm } from '@inertiajs/react';
import { Info, Send } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { formatRupiah } from '@/lib/format';
import orders from '@/routes/admin/orders';
import type { CourierServiceChoice } from '@/types/shipping';

/**
 * Handing an order's goods to a courier (P2-E2-T4).
 *
 * The waybill number is **optional on this form and required by the time the
 * dispatch completes**, which looks contradictory and is not: a courier served
 * by an aggregator issues the number when the booking is accepted, so there is
 * nothing for the packer to type. `DispatchShipment` refuses a dispatch that
 * ends up with no number from either source, and that refusal lands on the
 * courier field here.
 *
 * Each service shows what the shop *would* have charged for it, or nothing
 * when no rate reaches this address. The order is **not** re-priced either
 * way: `shipments.cost` is what the customer already agreed to pay, and a
 * branch choosing a dearer courier has made the shop's own decision rather
 * than the customer's.
 */
export function DispatchShipmentForm({
    orderId,
    services,
    blockedReason,
    chargedShipping,
}: {
    orderId: number;
    services: CourierServiceChoice[];
    /** Why this order cannot go out yet, straight from the server. */
    blockedReason: string | null;
    /** What the customer was charged for delivery. */
    chargedShipping: number;
}) {
    const form = useForm<{
        courier_service_id: string;
        tracking_number: string;
        notes: string;
    }>({
        courier_service_id: services[0]?.courier_service_id.toString() ?? '',
        tracking_number: '',
        notes: '',
    });

    const selected = services.find(
        (service) =>
            service.courier_service_id.toString() ===
            form.data.courier_service_id,
    );

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(orders.shipments.dispatch(orderId).url, {
            preserveScroll: true,
            onSuccess: () => form.reset('tracking_number', 'notes'),
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

    if (services.length === 0) {
        return (
            <Alert>
                <Info aria-hidden />
                <AlertDescription>
                    Belum ada kurir aktif. Aktifkan kurir dan layanannya
                    terlebih dahulu.
                </AlertDescription>
            </Alert>
        );
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-3">
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="grid gap-1.5">
                    <Label htmlFor="courier_service_id">Kurir & layanan</Label>
                    <Select
                        value={form.data.courier_service_id}
                        onValueChange={(value) =>
                            form.setData('courier_service_id', value)
                        }
                    >
                        <SelectTrigger id="courier_service_id">
                            <SelectValue placeholder="Pilih kurir" />
                        </SelectTrigger>
                        <SelectContent>
                            {services.map((service) => (
                                <SelectItem
                                    key={service.courier_service_id}
                                    value={service.courier_service_id.toString()}
                                >
                                    {service.display_name}
                                    {service.cost !== null &&
                                        ` · ${formatRupiah(service.cost)}`}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {selected?.estimate_label && (
                        <p className="text-xs text-muted-foreground">
                            Estimasi {selected.estimate_label}
                        </p>
                    )}
                </div>

                <div className="grid gap-1.5">
                    <Label htmlFor="tracking_number">Nomor resi</Label>
                    <Input
                        id="tracking_number"
                        value={form.data.tracking_number}
                        onChange={(event) =>
                            form.setData('tracking_number', event.target.value)
                        }
                        placeholder="Mis. JNE0012345678"
                        autoComplete="off"
                    />
                    <p className="text-xs text-muted-foreground">
                        Kosongkan hanya bila kurir yang menerbitkan nomornya
                        sendiri.
                    </p>
                </div>
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="shipment_notes">Catatan (opsional)</Label>
                <Input
                    id="shipment_notes"
                    value={form.data.notes}
                    onChange={(event) =>
                        form.setData('notes', event.target.value)
                    }
                    placeholder="Mis. dibungkus bubble wrap, 2 koli"
                />
            </div>

            {selected?.cost != null && selected.cost > chargedShipping && (
                <p className="text-xs text-muted-foreground">
                    Layanan ini {formatRupiah(selected.cost - chargedShipping)}{' '}
                    lebih mahal dari ongkos kirim yang dibayar pelanggan (
                    {formatRupiah(chargedShipping)}). Total pesanan tidak
                    berubah — selisihnya menjadi beban toko.
                </p>
            )}

            {form.errors.courier_service_id && (
                <p className="text-sm text-destructive">
                    {form.errors.courier_service_id}
                </p>
            )}
            {form.errors.tracking_number && (
                <p className="text-sm text-destructive">
                    {form.errors.tracking_number}
                </p>
            )}

            <Button
                type="submit"
                size="sm"
                className="self-start"
                disabled={form.processing}
            >
                <Send aria-hidden />
                {form.processing ? 'Mengirim…' : 'Tandai dikirim'}
            </Button>
        </form>
    );
}
