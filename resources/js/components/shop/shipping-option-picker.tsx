import { AlertTriangle, Clock, Truck } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ShippingOption } from '@/types/shipping';

/**
 * Choosing how the goods travel (P2-E2-T3).
 *
 * The prices are the **server's**: this screen is told what each option costs
 * and never sends a figure back, because `PlaceOrder` re-quotes whichever
 * service comes back against the real address and the real weight. A delivery
 * charge the browser chose would be a delivery charge anybody could choose.
 *
 * Three states, and the difference between the last two is the point:
 *
 * - options to compare, cheapest first;
 * - **no option, with the server's reason** — nobody serves this address, or a
 *   provider is down. The sentence tells the customer what to do instead, and
 *   it is never reconstructed here: one set of rules, one wording;
 * - no address chosen yet, which is not a failure and says so quietly.
 */
export function ShippingOptionPicker({
    options,
    unavailableReason,
    selectedId,
    onSelect,
    hasAddress,
    error,
}: {
    options: ShippingOption[];
    unavailableReason: string | null;
    selectedId: number | null;
    onSelect: (courierServiceId: number) => void;
    hasAddress: boolean;
    error?: string;
}) {
    if (!hasAddress) {
        return (
            <p className="text-sm text-muted-foreground">
                Pilih alamat pengiriman terlebih dahulu untuk melihat pilihan
                kurir dan ongkos kirimnya.
            </p>
        );
    }

    if (options.length === 0) {
        return (
            <Alert variant="destructive">
                <AlertTriangle aria-hidden />
                <AlertTitle>Pengiriman belum tersedia</AlertTitle>
                <AlertDescription>
                    {unavailableReason ??
                        'Belum ada layanan pengiriman ke alamat ini. Pilih "Ambil di Cabang" atau gunakan alamat lain.'}
                </AlertDescription>
            </Alert>
        );
    }

    return (
        <div>
            <ul className="flex flex-col gap-2">
                {options.map((option) => {
                    const selected = selectedId === option.courier_service_id;

                    return (
                        <li key={option.courier_service_id}>
                            <label
                                className={cn(
                                    'flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors',
                                    selected
                                        ? 'border-primary bg-primary/5'
                                        : 'hover:bg-accent',
                                )}
                            >
                                <input
                                    type="radio"
                                    name="courier_service_id"
                                    className="mt-1 size-4 shrink-0 accent-primary"
                                    checked={selected}
                                    onChange={() =>
                                        onSelect(option.courier_service_id)
                                    }
                                />

                                <Truck
                                    aria-hidden
                                    className="mt-0.5 size-5 shrink-0 text-muted-foreground"
                                />

                                <span className="min-w-0 flex-1 text-sm">
                                    <span className="block font-medium">
                                        {option.display_name}
                                    </span>
                                    {option.estimate_label && (
                                        <span className="flex items-center gap-1 text-muted-foreground">
                                            <Clock
                                                aria-hidden
                                                className="size-3"
                                            />
                                            Estimasi {option.estimate_label}
                                        </span>
                                    )}
                                    {option.description && (
                                        <span className="block text-xs text-muted-foreground">
                                            {option.description}
                                        </span>
                                    )}
                                </span>

                                <span className="shrink-0 text-sm font-medium tabular-nums">
                                    {formatRupiah(option.cost)}
                                </span>
                            </label>
                        </li>
                    );
                })}
            </ul>

            {error && <p className="mt-2 text-sm text-destructive">{error}</p>}
        </div>
    );
}
