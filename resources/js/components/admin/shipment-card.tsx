import { useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ExternalLink,
    PackageCheck,
    RefreshCw,
    Truck,
} from 'lucide-react';
import { StatusBadge } from '@/components/panel/status-badge';
import { ShipmentTimeline } from '@/components/shipment-timeline';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { formatDateTime, formatRupiah, formatWeight } from '@/lib/format';
import shipments from '@/routes/admin/shipments';
import type { FilterOption } from '@/types';
import { SHIPMENT_STATE_TONE } from '@/types/shipping';
import type { ShipmentDetail } from '@/types/shipping';

/**
 * One dispatch of an order's goods, with whatever may still be done about it
 * (P2-E2-T4, T6).
 *
 * Shown on the order's own detail screen, because that is where somebody is
 * standing when they decide to send it or chase it: the waybill is read beside
 * the address it is going to.
 *
 * Which controls appear is the **server's** decision, never this component's:
 * `can.manage` comes from `ShipmentPolicy`, and `is_trackable` from
 * `Shipment::isTrackable()`. A button offered for something the server will
 * refuse is worse than no button.
 *
 * The status form is how a **manual** courier's parcel moves at all — there is
 * nobody to ask, so what the branch types here *is* the timeline, which is why
 * the description field sits beside the status rather than being hidden behind
 * an "advanced" disclosure.
 */
export function ShipmentCard({
    shipment,
    statuses,
}: {
    shipment: ShipmentDetail;
    /** The statuses a branch may set by hand — `pending` is not among them. */
    statuses: FilterOption[];
}) {
    const canManage = shipment.can.manage ?? false;

    const statusForm = useForm<{
        status: string;
        description: string;
        location: string;
    }>({
        status: statuses[0]?.value ?? '',
        description: '',
        location: '',
    });

    // Typed with the error key it can come back with: the refusal when a
    // courier cannot be reached is reported on `tracking`.
    const trackingForm = useForm<{ tracking?: string }>({});

    const submitStatus = (event: React.FormEvent) => {
        event.preventDefault();
        statusForm.put(shipments.status.update(shipment.id).url, {
            preserveScroll: true,
            onSuccess: () =>
                statusForm.setData({
                    status: statuses[0]?.value ?? '',
                    description: '',
                    location: '',
                }),
        });
    };

    const refreshTracking = () => {
        trackingForm.post(shipments.tracking.refresh(shipment.id).url, {
            preserveScroll: true,
        });
    };

    return (
        <div className="flex flex-col gap-4 rounded-lg border p-4">
            <div className="flex flex-wrap items-start gap-3">
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusBadge
                            label={shipment.status_label}
                            tone={SHIPMENT_STATE_TONE[shipment.status]}
                        />
                        <span className="text-sm font-medium">
                            {shipment.display_name}
                        </span>
                    </div>
                    <p className="mt-1 font-mono text-xs break-all text-muted-foreground">
                        {shipment.tracking_number ?? 'Belum ada nomor resi'}
                        {shipment.external_id && ` · ${shipment.external_id}`}
                    </p>
                </div>

                <div className="text-right">
                    <p className="font-semibold tabular-nums">
                        {formatRupiah(shipment.cost)}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {formatWeight(shipment.weight_gram)}
                    </p>
                </div>
            </div>

            <dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                <Row
                    label="Dibuat"
                    value={formatDateTime(shipment.created_at)}
                />
                {shipment.estimate_label && (
                    <Row label="Estimasi" value={shipment.estimate_label} />
                )}
                {shipment.shipped_at && (
                    <Row
                        label="Diserahkan ke kurir"
                        value={formatDateTime(shipment.shipped_at)}
                    />
                )}
                {shipment.delivered_at && (
                    <Row
                        label="Diterima"
                        value={formatDateTime(shipment.delivered_at)}
                    />
                )}
                {shipment.dispatched_by && (
                    <Row label="Dikirim oleh" value={shipment.dispatched_by} />
                )}
                {shipment.tracking_synced_at && (
                    <Row
                        label="Terakhir dilacak"
                        value={formatDateTime(shipment.tracking_synced_at)}
                    />
                )}
            </dl>

            {shipment.notes && (
                <p className="text-sm text-muted-foreground">
                    Catatan: {shipment.notes}
                </p>
            )}

            {shipment.failure_reason && (
                <Alert variant="destructive">
                    <AlertTriangle aria-hidden />
                    <AlertTitle>Paket tidak sampai</AlertTitle>
                    <AlertDescription>
                        <p>{shipment.failure_reason}</p>
                        <p>
                            Status pesanan tidak berubah sendiri: tentukan
                            pengiriman ulang atau proses retur.
                        </p>
                    </AlertDescription>
                </Alert>
            )}

            {(shipment.tracking_url ||
                (canManage && shipment.is_trackable)) && (
                <div className="flex flex-wrap gap-2">
                    {shipment.tracking_url && (
                        <Button variant="outline" size="sm" asChild>
                            <a
                                href={shipment.tracking_url}
                                target="_blank"
                                rel="noreferrer noopener"
                            >
                                <ExternalLink aria-hidden />
                                Cek di situs kurir
                            </a>
                        </Button>
                    )}

                    {canManage && shipment.is_trackable && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={refreshTracking}
                            disabled={trackingForm.processing}
                        >
                            <RefreshCw aria-hidden />
                            {trackingForm.processing
                                ? 'Menghubungi kurir…'
                                : 'Perbarui dari kurir'}
                        </Button>
                    )}
                </div>
            )}

            {trackingForm.errors.tracking && (
                <p className="text-sm text-destructive">
                    {trackingForm.errors.tracking}
                </p>
            )}

            <div>
                <h4 className="mb-2 text-sm font-semibold">Posisi paket</h4>
                <ShipmentTimeline entries={shipment.timeline} />
            </div>

            {canManage && shipment.is_dispatched && shipment.is_open && (
                <form
                    onSubmit={submitStatus}
                    className="flex flex-col gap-3 border-t pt-4"
                >
                    <h4 className="text-sm font-semibold">
                        Perbarui status pengiriman
                    </h4>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor={`status-${shipment.id}`}>
                                Status
                            </Label>
                            <Select
                                value={statusForm.data.status}
                                onValueChange={(value) =>
                                    statusForm.setData('status', value)
                                }
                            >
                                <SelectTrigger id={`status-${shipment.id}`}>
                                    <SelectValue placeholder="Pilih status" />
                                </SelectTrigger>
                                <SelectContent>
                                    {statuses.map((status) => (
                                        <SelectItem
                                            key={status.value}
                                            value={status.value}
                                        >
                                            {status.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor={`location-${shipment.id}`}>
                                Lokasi (opsional)
                            </Label>
                            <Input
                                id={`location-${shipment.id}`}
                                value={statusForm.data.location}
                                onChange={(event) =>
                                    statusForm.setData(
                                        'location',
                                        event.target.value,
                                    )
                                }
                                placeholder="Mis. Gudang JNE Bandung"
                            />
                        </div>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor={`description-${shipment.id}`}>
                            Keterangan untuk pelanggan (opsional)
                        </Label>
                        <Input
                            id={`description-${shipment.id}`}
                            value={statusForm.data.description}
                            onChange={(event) =>
                                statusForm.setData(
                                    'description',
                                    event.target.value,
                                )
                            }
                            placeholder="Mis. Paket sudah diserahkan ke kurir cabang"
                        />
                        <p className="text-xs text-muted-foreground">
                            Pelanggan membaca kalimat ini di halaman pesanannya.
                        </p>
                    </div>

                    {statusForm.errors.status && (
                        <p className="text-sm text-destructive">
                            {statusForm.errors.status}
                        </p>
                    )}

                    <Button
                        type="submit"
                        size="sm"
                        className="self-start"
                        disabled={
                            statusForm.processing ||
                            statusForm.data.status === ''
                        }
                    >
                        <PackageCheck aria-hidden />
                        {statusForm.processing ? 'Menyimpan…' : 'Simpan status'}
                    </Button>
                </form>
            )}

            {!shipment.is_dispatched && (
                <p className="flex items-start gap-2 border-t pt-4 text-sm text-muted-foreground">
                    <Truck aria-hidden className="mt-0.5 size-4 shrink-0" />
                    Paket ini belum diserahkan ke kurir. Catat nomor resinya
                    lewat form pengiriman di atas.
                </p>
            )}
        </div>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex justify-between gap-4 sm:block">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="font-medium tabular-nums sm:font-normal">{value}</dd>
        </div>
    );
}
