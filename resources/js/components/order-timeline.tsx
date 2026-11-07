import { Circle, MessageSquare } from 'lucide-react';
import { cn } from '@/lib/utils';
import { formatDateTime } from '@/lib/format';
import type { OrderTimelineEntry } from '@/types/order';

type Props = {
    entries: OrderTimelineEntry[];
    /**
     * Whether to name the person behind each step. The panel shows it; the
     * customer's own screen does not, because which member of staff pressed
     * which button is not theirs to see.
     */
    showActor?: boolean;
    className?: string;
};

/**
 * An order's status history, oldest first (P1-E7-T6, T8).
 *
 * Read from `order_status_histories` rather than inferred from the order's
 * timestamp columns, so a step that happened is shown even when it has no
 * column of its own — and so the notes staff left on it come with it.
 *
 * Shared by the customer's order page and the panel's, because it is the same
 * sequence of events; only whether the actor is named differs. The last entry
 * is marked current, since the timeline always ends where the order is now.
 */
export function OrderTimeline({
    entries,
    showActor = false,
    className,
}: Props) {
    if (entries.length === 0) {
        return (
            <p className={cn('text-sm text-muted-foreground', className)}>
                Belum ada riwayat status.
            </p>
        );
    }

    return (
        <ol className={cn('flex flex-col', className)}>
            {entries.map((entry, index) => {
                const isLast = index === entries.length - 1;
                const Icon = entry.is_note ? MessageSquare : Circle;

                return (
                    <li key={entry.id} className="flex gap-3">
                        <div className="flex flex-col items-center">
                            <span
                                className={cn(
                                    'flex size-6 shrink-0 items-center justify-center rounded-full border',
                                    isLast && !entry.is_note
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'bg-background text-muted-foreground',
                                )}
                            >
                                <Icon aria-hidden className="size-3" />
                            </span>
                            {!isLast && (
                                <span
                                    aria-hidden
                                    className="w-px flex-1 bg-border"
                                />
                            )}
                        </div>

                        <div className={cn('min-w-0 pb-5', isLast && 'pb-0')}>
                            <p className="text-sm font-medium">
                                {entry.is_note ? 'Catatan' : entry.to_label}
                            </p>
                            {isLast && !entry.is_note && (
                                <p className="text-xs text-primary">
                                    Status sekarang
                                </p>
                            )}
                            <p className="text-xs text-muted-foreground">
                                {formatDateTime(entry.created_at)}
                                {showActor && entry.changed_by
                                    ? ` · ${entry.changed_by}`
                                    : ''}
                            </p>
                            {entry.note && (
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {entry.note}
                                </p>
                            )}
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
