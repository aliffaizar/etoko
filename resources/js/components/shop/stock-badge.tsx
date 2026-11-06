import { cn } from '@/lib/utils';
import { formatNumber } from '@/lib/format';

type Props = {
    /** Units the chosen branch can still sell: on-hand minus reserved (BR-6). */
    availableQuantity: number;
    /**
     * False when the branch has said it does not stock this product at all.
     * Takes precedence over the quantity, because "we do not sell this here"
     * and "we are out of it" are different answers to somebody waiting.
     */
    isSoldAtBranch?: boolean;
    /** Below this, the exact figure is shown to signal urgency honestly. */
    lowThreshold?: number;
    className?: string;
};

const DEFAULT_LOW_THRESHOLD = 5;

/**
 * How much of a product the **chosen branch** has left (BR-2, BR-6).
 *
 * The price on a card is the catalogue's and the same everywhere (ADR-031);
 * this badge is the one figure that is genuinely per branch, which is why it
 * names the branch in its wording.
 *
 * The figure is `quantity - reserved_quantity`, so stock another shopper is
 * already holding in checkout is not advertised here. Four states, because
 * they lead to four different decisions: the branch does not carry it at all,
 * it carries it but has none right now, few left and worth hurrying, or simply
 * available — and the exact count is only shown in the "few left" state, where
 * it actually changes what somebody does.
 */
export function StockBadge({
    availableQuantity,
    isSoldAtBranch = true,
    lowThreshold = DEFAULT_LOW_THRESHOLD,
    className,
}: Props) {
    // Both "not carried here" and "none left" read as unavailable, so they
    // share a tone; only the wording tells them apart.
    const tone =
        !isSoldAtBranch || availableQuantity <= 0
            ? 'bg-muted text-muted-foreground'
            : availableQuantity <= lowThreshold
              ? 'bg-amber-500/10 text-amber-700 dark:text-amber-400'
              : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400';

    const label = !isSoldAtBranch
        ? 'Tidak dijual di cabang ini'
        : availableQuantity <= 0
          ? 'Stok habis di cabang ini'
          : availableQuantity <= lowThreshold
            ? `Sisa ${formatNumber(availableQuantity)}`
            : 'Tersedia';

    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                tone,
                className,
            )}
        >
            {label}
        </span>
    );
}
