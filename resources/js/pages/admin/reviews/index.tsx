import { Head, Link, router, useForm } from '@inertiajs/react';
import { Star } from 'lucide-react';
import { useState } from 'react';
import ReviewController from '@/actions/App/Http/Controllers/Admin/ReviewController';
import Heading from '@/components/heading';
import { SimplePagination } from '@/components/panel/simple-pagination';
import { StatusBadge } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { index as reviewsIndex } from '@/routes/admin/reviews';
import type { FilterOption, PaginatedReviews, ReviewAdminRow } from '@/types';

type ReviewsIndexProps = {
    reviews: PaginatedReviews;
    filters: { status: string };
    options: { statuses: FilterOption[] };
};

/**
 * The review queue (P2-E8-T3, ADR-068).
 *
 * Pending reviews come first. Showing and hiding is central; answering is for
 * whoever may moderate in that branch, and the server decides which buttons
 * are real — the flags on each row only decide which are offered.
 */
export default function ReviewsIndex({
    reviews,
    filters,
    options,
}: ReviewsIndexProps) {
    return (
        <>
            <Head title="Ulasan" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Ulasan Pelanggan"
                    description="Ulasan tampil di halaman produk setelah disetujui. Hanya pembelian yang sudah diterima yang bisa diulas."
                />

                <nav
                    aria-label="Filter status"
                    className="flex flex-wrap gap-2"
                >
                    <FilterLink
                        active={filters.status === ''}
                        href={reviewsIndex()}
                    >
                        Semua
                    </FilterLink>
                    {options.statuses.map((status) => (
                        <FilterLink
                            key={status.value}
                            active={filters.status === status.value}
                            href={reviewsIndex({
                                query: { status: status.value },
                            })}
                        >
                            {status.label}
                        </FilterLink>
                    ))}
                </nav>

                {reviews.data.length === 0 ? (
                    <p className="rounded-lg border p-8 text-center text-sm text-muted-foreground">
                        Tidak ada ulasan untuk filter ini.
                    </p>
                ) : (
                    <ul className="flex flex-col gap-4">
                        {reviews.data.map((review) => (
                            <ReviewCard key={review.id} review={review} />
                        ))}
                    </ul>
                )}

                <SimplePagination links={reviews.links} />
            </div>
        </>
    );
}

function FilterLink({
    active,
    href,
    children,
}: {
    active: boolean;
    href: Parameters<typeof Link>[0]['href'];
    children: React.ReactNode;
}) {
    return (
        <Link
            href={href}
            preserveScroll
            aria-current={active ? 'page' : undefined}
            className={cn(
                'rounded-md border px-3 py-1 text-sm',
                active ? 'bg-muted font-medium' : 'hover:bg-muted',
            )}
        >
            {children}
        </Link>
    );
}

function ReviewCard({ review }: { review: ReviewAdminRow }) {
    const [replying, setReplying] = useState(false);

    return (
        <li className="flex flex-col gap-3 rounded-lg border p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="font-medium">{review.product_name}</p>
                    <p className="text-xs text-muted-foreground">
                        {review.customer_name} · {review.branch_name} ·{' '}
                        {formatDateTime(review.created_at)}
                        {review.verified && ' · pembelian terverifikasi'}
                    </p>
                </div>
                <StatusBadge
                    label={review.status_label}
                    tone={review.status_tone}
                />
            </div>

            <div
                className="flex items-center gap-1"
                aria-label={`${review.rating} dari 5 bintang`}
            >
                {[1, 2, 3, 4, 5].map((value) => (
                    <Star
                        key={value}
                        aria-hidden
                        className={cn(
                            'size-4',
                            value <= review.rating
                                ? 'fill-amber-400 text-amber-400'
                                : 'text-muted-foreground',
                        )}
                    />
                ))}
                {review.title && (
                    <span className="ml-2 text-sm font-medium">
                        {review.title}
                    </span>
                )}
            </div>

            {review.comment && (
                <p className="text-sm whitespace-pre-line">{review.comment}</p>
            )}

            {review.images.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {review.images.map((url) => (
                        <a
                            key={url}
                            href={url}
                            target="_blank"
                            rel="noreferrer"
                        >
                            <img
                                src={url}
                                alt="Foto ulasan"
                                className="size-16 rounded-md border object-cover"
                            />
                        </a>
                    ))}
                </div>
            )}

            {review.reply && !replying && (
                <div className="rounded-md bg-muted p-3 text-sm">
                    <p className="text-xs font-medium">
                        Balasan toko
                        {review.replied_by ? ` oleh ${review.replied_by}` : ''}
                    </p>
                    <p className="whitespace-pre-line">{review.reply}</p>
                </div>
            )}

            {replying && (
                <ReplyForm review={review} onDone={() => setReplying(false)} />
            )}

            <div className="flex flex-wrap gap-2">
                {review.can_approve && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            router.post(
                                ReviewController.approve(review.id).url,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Tampilkan
                    </Button>
                )}
                {review.can_reject && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            router.post(
                                ReviewController.reject(review.id).url,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Sembunyikan
                    </Button>
                )}
                {review.can_reply && !replying && (
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setReplying(true)}
                    >
                        {review.reply ? 'Ubah balasan' : 'Balas'}
                    </Button>
                )}
            </div>
        </li>
    );
}

function ReplyForm({
    review,
    onDone,
}: {
    review: ReviewAdminRow;
    onDone: () => void;
}) {
    const form = useForm({ reply: review.reply ?? '' });

    return (
        <form
            className="flex flex-col gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(ReviewController.reply(review.id).url, {
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
        >
            <Label htmlFor={`reply-${review.id}`}>Balasan toko</Label>
            <Textarea
                id={`reply-${review.id}`}
                rows={3}
                maxLength={2000}
                value={form.data.reply}
                onChange={(event) => form.setData('reply', event.target.value)}
            />
            {form.errors.reply && (
                <p className="text-xs text-destructive">{form.errors.reply}</p>
            )}
            <div className="flex gap-2">
                <Button
                    type="submit"
                    size="sm"
                    disabled={form.processing || form.data.reply.trim() === ''}
                >
                    Simpan balasan
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={onDone}
                >
                    Batal
                </Button>
            </div>
        </form>
    );
}

ReviewsIndex.layout = {
    breadcrumbs: [{ title: 'Ulasan', href: reviewsIndex() }],
};
