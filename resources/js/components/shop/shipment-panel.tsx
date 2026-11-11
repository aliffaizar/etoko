import { AlertTriangle, ExternalLink, Package, Store } from 'lucide-react';
import { StatusBadge } from '@/components/panel/status-badge';
import { ShipmentTimeline } from '@/components/shipment-timeline';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { formatDateTime, formatRupiah, formatWeight } from '@/lib/format';
import { SHIPMENT_STATE_TONE } from '@/types/shipping';
import type { CustomerShipment } from '@/types/shipping';

/**
 * Where the customer's parcel is (P2-E2-T5).
 *
 * On the order page rather than on a tracking screen of its own, for the same
 * reason the payment panel is: the waybill, the timeline and the address are
 * all about this order, and a customer who closed a separate tracking page
 * would have nowhere obvious to go back to.
 *
 * Every dispatch is shown, not only the live one, so a parcel that came back
 * and was re-sent reads as the two journeys it was — that history is exactly
 * what a customer asking "where is my order?" needs.
 *
 * A failed or returned parcel leads with its reason. The order's own status is
 * deliberately left alone by the shop in that case, so this panel is the only
 * place that says what went wrong.
 */
export function ShipmentPanel({
    shipments,
    pickupCode,
    branchName,
}: {
    shipments: CustomerShipment[];
    /** Issued only while the goods are actually waiting at the branch. */
    pickupCode: string | null;
    branchName: string;
}) {
    if (pickupCode !== null) {
        return (
            <section className="rounded-lg border p-4">
                <h2 className="mb-1 flex items-center gap-2 text-sm font-semibold">
                    <Store aria-hidden className="size-4" />
                    Siap diambil di {branchName}
                </h2>
                <p className="text-sm text-muted-foreground">
                    Tunjukkan kode ini di kasir. Bawa identitas diri bila yang
                    mengambil bukan Anda sendiri.
                </p>

                <p className="mt-3 font-mono text-2xl font-semibold tracking-[0.2em]">
                    {pickupCode}
                </p>
            </section>
        );
    }

    if (shipments.length === 0) {
        return null;
    }

    return (
        <section className="rounded-lg border p-4">
            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold">
                <Package aria-hidden className="size-4" />
                Pengiriman
            </h2>

            <div className="flex flex-col gap-4">
                {shipments.map((shipment, index) => (
                    <div key={shipment.id}>
                        {index > 0 && <Separator className="mb-4" />}

                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div className="min-w-0">
                                <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                    {shipment.display_name}
                                    <StatusBadge
                                        label={shipment.status_label}
                                        tone={
                                            SHIPMENT_STATE_TONE[shipment.status]
                                        }
                                    />
                                </p>
                                {shipment.tracking_number && (
                                    <p className="font-mono text-xs break-all text-muted-foreground">
                                        Resi {shipment.tracking_number}
                                    </p>
                                )}
                            </div>

                            {shipment.tracking_url && (
                                <Button variant="outline" size="sm" asChild>
                                    <a
                                        href={shipment.tracking_url}
                                        target="_blank"
                                        rel="noreferrer noopener"
                                    >
                                        <ExternalLink aria-hidden />
                                        Cek di kurir
                                    </a>
                                </Button>
                            )}
                        </div>

                        <dl className="mt-2 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                            <Row
                                label="Ongkos kirim"
                                value={formatRupiah(shipment.cost)}
                            />
                            <Row
                                label="Berat paket"
                                value={formatWeight(shipment.weight_gram)}
                            />
                            {shipment.estimate_label && (
                                <Row
                                    label="Estimasi tiba"
                                    value={shipment.estimate_label}
                                />
                            )}
                            {shipment.shipped_at && (
                                <Row
                                    label="Dikirim"
                                    value={formatDateTime(shipment.shipped_at)}
                                />
                            )}
                            {shipment.delivered_at && (
                                <Row
                                    label="Diterima"
                                    value={formatDateTime(
                                        shipment.delivered_at,
                                    )}
                                />
                            )}
                        </dl>

                        {shipment.failure_reason && (
                            <Alert variant="destructive" className="mt-3">
                                <AlertTriangle aria-hidden />
                                <AlertTitle>
                                    Paket belum sampai ke Anda
                                </AlertTitle>
                                <AlertDescription>
                                    <p>{shipment.failure_reason}</p>
                                    <p>
                                        Hubungi {branchName} untuk pengiriman
                                        ulang atau pengembalian dana.
                                    </p>
                                </AlertDescription>
                            </Alert>
                        )}

                        <ShipmentTimeline
                            entries={shipment.timeline}
                            className="mt-4"
                        />
                    </div>
                ))}
            </div>
        </section>
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
