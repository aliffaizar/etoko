import { useForm } from '@inertiajs/react';
import { Star } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import ReviewController from '@/actions/App/Http/Controllers/Shop/ReviewController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { CustomerReviewState } from '@/types';

type ReviewLineProps = {
    orderId: number;
    itemId: number;
    /** What the customer already wrote for this line, if anything. */
    review: CustomerReviewState | null;
    /** Whether the order has reached a point where a review is allowed. */
    canReview: boolean;
};

const MAX_PHOTOS = 5;

/**
 * The review of one order line, under the line on the order page (P2-E8-T3).
 *
 * Shows what the customer wrote and where it stands, or — once the goods have
 * arrived and nothing is written yet — the form. The server decides eligibility
 * again; `canReview` only decides what is offered.
 */
export function ReviewLine({
    orderId,
    itemId,
    review,
    canReview,
}: ReviewLineProps) {
    const [open, setOpen] = useState(false);

    if (review) {
        return (
            <p className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                <Star
                    aria-hidden
                    className="size-3.5 fill-amber-400 text-amber-400"
                />
                Ulasan Anda: {review.rating} bintang · {review.status_label}
            </p>
        );
    }

    if (!canReview) {
        return null;
    }

    if (!open) {
        return (
            <Button
                type="button"
                variant="link"
                size="sm"
                className="h-auto px-0 text-xs"
                onClick={() => setOpen(true)}
            >
                Tulis ulasan
            </Button>
        );
    }

    return (
        <ReviewForm
            orderId={orderId}
            itemId={itemId}
            onCancel={() => setOpen(false)}
        />
    );
}

function ReviewForm({
    orderId,
    itemId,
    onCancel,
}: {
    orderId: number;
    itemId: number;
    onCancel: () => void;
}) {
    const form = useForm<{
        rating: number;
        title: string;
        comment: string;
        photos: File[];
    }>({
        rating: 5,
        title: '',
        comment: '',
        photos: [],
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        form.post(
            ReviewController.store.url({ order: orderId, item: itemId }),
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => form.reset(),
            },
        );
    };

    // The server's refusal of the whole review arrives under `review`, which is
    // not a field of the form, so it is read through a wider type.
    const errors: Record<string, string | undefined> = form.errors;

    return (
        <form
            onSubmit={submit}
            className="mt-3 flex flex-col gap-3 rounded-md border p-3 text-sm"
        >
            <fieldset className="flex flex-col gap-1.5">
                <legend className="mb-1 font-medium">Bintang</legend>
                <div className="flex gap-1">
                    {[1, 2, 3, 4, 5].map((value) => (
                        <button
                            key={value}
                            type="button"
                            aria-label={`${value} bintang`}
                            aria-pressed={form.data.rating === value}
                            onClick={() => form.setData('rating', value)}
                            className="rounded p-0.5 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <Star
                                aria-hidden
                                className={cn(
                                    'size-5',
                                    value <= form.data.rating
                                        ? 'fill-amber-400 text-amber-400'
                                        : 'text-muted-foreground',
                                )}
                            />
                        </button>
                    ))}
                </div>
                {errors.rating && (
                    <p className="text-xs text-destructive">{errors.rating}</p>
                )}
            </fieldset>

            <div className="grid gap-1.5">
                <Label htmlFor={`review-title-${itemId}`}>
                    Judul{' '}
                    <span className="text-muted-foreground">(opsional)</span>
                </Label>
                <Input
                    id={`review-title-${itemId}`}
                    value={form.data.title}
                    maxLength={120}
                    onChange={(event) =>
                        form.setData('title', event.target.value)
                    }
                />
                {errors.title && (
                    <p className="text-xs text-destructive">{errors.title}</p>
                )}
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor={`review-comment-${itemId}`}>
                    Ulasan{' '}
                    <span className="text-muted-foreground">(opsional)</span>
                </Label>
                <Textarea
                    id={`review-comment-${itemId}`}
                    rows={3}
                    maxLength={2000}
                    value={form.data.comment}
                    onChange={(event) =>
                        form.setData('comment', event.target.value)
                    }
                />
                {errors.comment && (
                    <p className="text-xs text-destructive">{errors.comment}</p>
                )}
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor={`review-photos-${itemId}`}>
                    Foto{' '}
                    <span className="text-muted-foreground">
                        (maks. {MAX_PHOTOS}, JPG/PNG/WebP, 2 MB)
                    </span>
                </Label>
                <input
                    id={`review-photos-${itemId}`}
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    multiple
                    className="text-xs"
                    onChange={(event) =>
                        form.setData(
                            'photos',
                            Array.from(event.target.files ?? []).slice(
                                0,
                                MAX_PHOTOS,
                            ),
                        )
                    }
                />
                {Object.entries(errors)
                    .filter(([key]) => key.startsWith('photos'))
                    .map(([key, message]) => (
                        <p key={key} className="text-xs text-destructive">
                            {message}
                        </p>
                    ))}
            </div>

            {errors.review && (
                <p className="text-xs text-destructive">{errors.review}</p>
            )}

            <div className="flex gap-2">
                <Button type="submit" size="sm" disabled={form.processing}>
                    Kirim ulasan
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={onCancel}
                >
                    Batal
                </Button>
            </div>
        </form>
    );
}
