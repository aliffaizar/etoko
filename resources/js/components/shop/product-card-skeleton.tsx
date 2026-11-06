import { Skeleton } from '@/components/ui/skeleton';

type Props = {
    count?: number;
};

/**
 * The placeholder a deferred product rail or grid shows while it loads.
 *
 * Shaped like the card it replaces — square image, two lines, a price — so the
 * layout does not jump when the real data arrives.
 */
export function ProductCardSkeleton({ count = 4 }: Props) {
    return (
        <>
            {Array.from({ length: count }).map((_, index) => (
                <div
                    key={index}
                    className="flex flex-col overflow-hidden rounded-xl border bg-card"
                >
                    <Skeleton className="aspect-square rounded-none" />
                    <div className="flex flex-col gap-2 p-3">
                        <Skeleton className="h-3 w-16" />
                        <Skeleton className="h-4 w-full" />
                        <Skeleton className="h-4 w-2/3" />
                        <Skeleton className="h-5 w-24" />
                    </div>
                </div>
            ))}
        </>
    );
}
