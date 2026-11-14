<?php

namespace App\Support;

use App\Models\Review;
use App\Models\ReviewImage;
use App\Models\User;

/**
 * The shapes the review screens read (P2-E8-T3).
 *
 * The storefront never sees a customer's surname beyond an initial, and never
 * sees a review that is not on show: `publicRow()` is the only shape a shopper
 * receives, and the caller is expected to pass it approved reviews only.
 */
final class ReviewPresenter
{
    /**
     * @return array{id: int, rating: int, title: string|null, comment: string|null, author: string, verified: bool, created_at: string, images: array<int, string>, reply: array{text: string, replied_at: string}|null}
     */
    public static function publicRow(Review $review): array
    {
        return [
            'id' => $review->getKey(),
            'rating' => $review->rating,
            'title' => $review->title,
            'comment' => $review->comment,
            'author' => self::initialOf($review->user->name),
            'verified' => (bool) $review->is_verified_purchase,
            'created_at' => $review->created_at?->toIso8601String() ?? '',
            'images' => $review->images->map(fn (ReviewImage $image): string => $image->url())->values()->all(),
            'reply' => $review->reply === null ? null : [
                'text' => $review->reply,
                'replied_at' => $review->replied_at?->toIso8601String() ?? '',
            ],
        ];
    }

    /**
     * @return array{id: int, product_name: string, customer_name: string, branch_name: string, rating: int, title: string|null, comment: string|null, status: string, status_label: string, status_tone: string, verified: bool, images: array<int, string>, reply: string|null, replied_by: string|null, created_at: string, can_approve: bool, can_reject: bool, can_reply: bool}
     */
    public static function adminRow(Review $review, User $viewer): array
    {
        $status = $review->status;

        return [
            'id' => $review->getKey(),
            'product_name' => $review->product->name,
            'customer_name' => $review->user->name,
            'branch_name' => $review->branch->name ?? '—',
            'rating' => $review->rating,
            'title' => $review->title,
            'comment' => $review->comment,
            'status' => $status->value,
            'status_label' => $status->label(),
            'status_tone' => match ($status->value) {
                'approved' => 'success',
                'rejected' => 'danger',
                default => 'warning',
            },
            'verified' => (bool) $review->is_verified_purchase,
            'images' => $review->images->map(fn (ReviewImage $image): string => $image->url())->values()->all(),
            'reply' => $review->reply,
            'replied_by' => $review->repliedBy?->name,
            'created_at' => $review->created_at?->toIso8601String() ?? '',
            'can_approve' => $status->value !== 'approved' && $viewer->can('moderate', $review),
            'can_reject' => $status->value !== 'rejected' && $viewer->can('moderate', $review),
            'can_reply' => $review->replyBlockedReason() === null && $viewer->can('reply', $review),
        ];
    }

    private static function initialOf(?string $name): string
    {
        $first = trim((string) $name);

        return $first === '' ? 'Pelanggan' : mb_strtoupper(mb_substr($first, 0, 1)).'.';
    }
}
