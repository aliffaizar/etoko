import { CheckCircle2, Circle, MapPin, Store } from 'lucide-react';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ShipmentTrackingEntry } from '@/types/shipping';

/**
 * What the courier said about a parcel, oldest first (P2-E2-T5).
 *
 * Read from `shipment_tracking_events` rather than derived from the shipment's
 * current status: the timeline is the record of what was *reported*, so a
 * parcel that moved three times between two sweeps reads as three steps rather
 * than appearing to teleport.
 *
 * Shared by the customer's order page and the panel's shipment card, because
 * it is the same sequence of events — a customer asking "where is my parcel?"
 * and a staff member answering them should be looking at the same thing.
 *
 * Each row says **who** reported it, because the two sources are not equally
 * authoritative: a customer reading "Terkirim" deserves to know whether the
 * courier scanned it or the branch typed it.
 */
export function ShipmentTimeline({
    entries,
    className,
}: {
    entries: ShipmentTrackingEntry[];
    className?: string;
}) {
    if (entries.length === 0) {
        return (
            <p className={cn('text-sm text-muted-foreground', className)}>
                Belum ada pembaruan posisi paket.
            </p>
        );
    }

    return (
        <ol className={cn('flex flex-col', className)}>
            {entries.map((entry, index) => {
                const isLatest = index === entries.length - 1;
                const Icon =
                    entry.status === 'delivered' ? CheckCircle2 : Circle;
                const SourceIcon = entry.source === 'branch' ? Store : MapPin;

                return (
                    <li key={entry.id} className="flex gap-3">
                        <div className="flex flex-col items-center">
                            <span
                                className={cn(
                                    'flex size-6 shrink-0 items-center justify-center rounded-full border',
                                    isLatest
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'bg-background text-muted-foreground',
                                )}
                            >
                                <Icon aria-hidden className="size-3" />
                            </span>
                            {!isLatest && (
                                <span
                                    aria-hidden
                                    className="w-px flex-1 bg-border"
                                />
                            )}
                        </div>

                        <div className={cn('min-w-0 pb-5', isLatest && 'pb-0')}>
                            <p className="text-sm font-medium">
                                {entry.status_label}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {entry.description}
                            </p>
                            <p className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                                <span>{formatDateTime(entry.event_at)}</span>
                                {entry.location && (
                                    <span className="inline-flex items-center gap-1">
                                        <SourceIcon
                                            aria-hidden
                                            className="size-3"
                                        />
                                        {entry.location}
                                    </span>
                                )}
                                <span>· {entry.source_label}</span>
                            </p>
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
