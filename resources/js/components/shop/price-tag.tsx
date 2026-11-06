import { formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';

type Props = {
    /** Whole rupiah (ADR-008). Null renders a dash rather than "Rp 0". */
    price: number | null;
    /** The struck-through price, shown only when it is genuinely higher. */
    compareAtPrice?: number | null;
    /** Prefixes "Mulai dari" when a product's options are not all one price. */
    hasRange?: boolean;
    size?: 'sm' | 'md' | 'lg';
    className?: string;
};

const PRICE_SIZES = {
    sm: 'text-sm',
    md: 'text-base',
    lg: 'text-2xl',
} as const;

const COMPARE_SIZES = {
    sm: 'text-xs',
    md: 'text-xs',
    lg: 'text-sm',
} as const;

/**
 * A price as the shop shows it, with its saving.
 *
 * The figure always comes from the server's `EffectivePriceResolver`, so this
 * component never computes or discounts anything — it only decides how the two
 * numbers are drawn. A `compare_at_price` at or below the price advertises a
 * saving that does not exist, so it is dropped rather than rendered (the
 * backend refuses to store one, and this is the second line of defence).
 */
export function PriceTag({
    price,
    compareAtPrice = null,
    hasRange = false,
    size = 'md',
    className,
}: Props) {
    if (price === null) {
        return <span className="text-sm text-muted-foreground">—</span>;
    }

    const isDiscounted = compareAtPrice !== null && compareAtPrice > price;
    const savedPercent = isDiscounted
        ? Math.round(((compareAtPrice - price) / compareAtPrice) * 100)
        : 0;

    return (
        <div className={cn('flex flex-wrap items-baseline gap-x-2', className)}>
            {hasRange && (
                <span className="text-xs text-muted-foreground">
                    Mulai dari
                </span>
            )}
            <span
                className={cn(
                    'font-semibold tracking-tight tabular-nums',
                    PRICE_SIZES[size],
                )}
            >
                {formatRupiah(price)}
            </span>
            {isDiscounted && (
                <>
                    <span
                        className={cn(
                            'text-muted-foreground tabular-nums line-through',
                            COMPARE_SIZES[size],
                        )}
                    >
                        {formatRupiah(compareAtPrice)}
                    </span>
                    <span
                        className={cn(
                            'rounded bg-destructive/10 px-1.5 py-0.5 font-medium text-destructive',
                            COMPARE_SIZES[size],
                        )}
                    >
                        -{savedPercent}%
                    </span>
                </>
            )}
        </div>
    );
}
