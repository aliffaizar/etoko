import { MessageSquare, Star } from 'lucide-react';
import { ShopEmpty } from '@/components/shop/shop-empty';
import { formatDateTime, formatNumber } from '@/lib/format';
import type { ShopReview, ShopReviews } from '@/types';

/**
 * The product page's reviews tab (P2-E8-T3, ADR-068).
 *
 * Shows only what moderation has let through, with the seller's answer under
 * each review where there is one. The shopper sees a first name's initial and
 * a "pembelian terverifikasi" mark, never more about the person who wrote it.
 */
export function ProductReviews({ reviews }: { reviews: ShopReviews }) {
    if (reviews.items.length === 0) {
        return (
            <ShopEmpty
                icon={MessageSquare}
                title="Belum ada ulasan"
                description="Ulasan dari pembeli yang sudah menerima produk ini akan tampil di sini."
            />
        );
    }

    return (
        <div className="flex flex-col gap-6">
            <p className="flex items-center gap-2 text-sm">
                <Stars value={reviews.summary.average} />
                <span className="font-medium">
                    {reviews.summary.average.toFixed(1)}
                </span>
                <span className="text-muted-foreground">
                    dari {formatNumber(reviews.summary.count)} ulasan
                </span>
            </p>

            <ul className="divide-y">
                {reviews.items.map((review) => (
                    <ReviewItem key={review.id} review={review} />
                ))}
            </ul>
        </div>
    );
}

function ReviewItem({ review }: { review: ShopReview }) {
    return (
        <li className="flex flex-col gap-2 py-4 first:pt-0">
            <div className="flex flex-wrap items-center gap-2 text-sm">
                <Stars value={review.rating} />
                {review.title && (
                    <span className="font-medium">{review.title}</span>
                )}
            </div>

            {review.comment && (
                <p className="text-sm whitespace-pre-line">{review.comment}</p>
            )}

            {review.images.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {review.images.map((url) => (
                        <img
                            key={url}
                            src={url}
                            alt="Foto dari ulasan pembeli"
                            loading="lazy"
                            className="size-16 rounded-md border object-cover"
                        />
                    ))}
                </div>
            )}

            <p className="text-xs text-muted-foreground">
                {review.author}
                {review.verified && ' · Pembelian terverifikasi'} ·{' '}
                {formatDateTime(review.created_at)}
            </p>

            {review.reply && (
                <div className="rounded-md bg-muted p-3 text-sm">
                    <p className="text-xs font-medium">Balasan dari toko</p>
                    <p className="whitespace-pre-line">{review.reply.text}</p>
                </div>
            )}
        </li>
    );
}

function Stars({ value }: { value: number }) {
    const rounded = Math.round(value);

    return (
        <span
            className="inline-flex items-center gap-0.5"
            aria-label={`${rounded} dari 5 bintang`}
        >
            {[1, 2, 3, 4, 5].map((star) => (
                <Star
                    key={star}
                    aria-hidden
                    className={
                        star <= rounded
                            ? 'size-3.5 fill-amber-400 text-amber-400'
                            : 'size-3.5 text-muted-foreground'
                    }
                />
            ))}
        </span>
    );
}
