import { Link } from '@inertiajs/react';
import { ImageOff, Star } from 'lucide-react';
import { PriceTag } from '@/components/shop/price-tag';
import { StockBadge } from '@/components/shop/stock-badge';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import products from '@/routes/shop/products';
import type { ShopProductCard } from '@/types';

type Props = {
    product: ShopProductCard;
    className?: string;
};

/**
 * One product in a grid or a rail.
 *
 * The same card on the front page and in the catalogue, fed by one presenter
 * server side (`ProductCardPresenter`), so the two can never show different
 * prices for the same product.
 *
 * The price is the catalogue's and reads the same at every branch (ADR-031).
 * The stock badge is the one thing that changes with the branch, and a product
 * the branch has none of — or does not carry at all — is still shown, dimmed,
 * with that said out loud: this is one shop with one catalogue, so hiding it
 * would make somebody conclude the shop does not sell it. The "ketersediaan"
 * filter is there for shoppers who only want what they can have today.
 */
export function ProductCard({ product, className }: Props) {
    return (
        <Link
            href={products.show(product.slug)}
            prefetch
            cacheFor="30s"
            className={cn(
                'group flex flex-col overflow-hidden rounded-xl border bg-card transition-colors hover:border-primary/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                className,
            )}
        >
            <div className="relative aspect-square overflow-hidden bg-muted">
                {product.image_url ? (
                    <img
                        src={product.image_url}
                        alt={product.image_alt}
                        loading="lazy"
                        className={cn(
                            'size-full object-cover transition-transform duration-300 group-hover:scale-105',
                            (!product.is_in_stock ||
                                !product.is_sold_at_branch) &&
                                'opacity-50 saturate-50',
                        )}
                    />
                ) : (
                    <div className="flex size-full items-center justify-center text-muted-foreground">
                        <ImageOff className="size-8" aria-hidden />
                    </div>
                )}

                {product.is_featured && (
                    <span className="absolute top-2 left-2 rounded-full bg-primary px-2 py-0.5 text-xs font-medium text-primary-foreground">
                        Unggulan
                    </span>
                )}
            </div>

            <div className="flex flex-1 flex-col gap-2 p-3">
                <p className="text-xs text-muted-foreground">
                    {product.category_name}
                </p>

                <h3 className="line-clamp-2 text-sm leading-snug font-medium">
                    {product.name}
                </h3>

                <PriceTag
                    price={product.price}
                    compareAtPrice={product.compare_at_price}
                    hasRange={product.has_price_range}
                    className="mt-auto"
                />

                <div className="flex flex-wrap items-center gap-2">
                    <StockBadge
                        availableQuantity={product.available_quantity}
                        isSoldAtBranch={product.is_sold_at_branch}
                    />

                    {product.rating_count > 0 && (
                        <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                            <Star
                                className="size-3 fill-amber-400 text-amber-400"
                                aria-hidden
                            />
                            {product.rating_avg.toFixed(1)}
                            <span className="sr-only">dari 5</span>(
                            {formatNumber(product.rating_count)})
                        </span>
                    )}

                    {product.sold_count > 0 && (
                        <span className="text-xs text-muted-foreground">
                            {formatNumber(product.sold_count)} terjual
                        </span>
                    )}
                </div>
            </div>
        </Link>
    );
}
