<?php

namespace App\Actions\Review;

use App\Models\Review;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The seller's answer to a review on show (P2-E8-T3, ADR-068).
 *
 * One answer per review; a second write replaces the first and records who
 * wrote it last. Which branch's reviews a replier may reach is decided by the
 * `BelongsToBranch` scope before the review is ever bound.
 */
final class ReplyToReview
{
    /**
     * @throws ValidationException When the review is not on show.
     */
    public function handle(Review $review, string $reply, User $replier): Review
    {
        $blocked = $review->replyBlockedReason();

        if ($blocked !== null) {
            throw ValidationException::withMessages(['reply' => $blocked]);
        }

        $review->update([
            'reply' => $reply,
            'replied_by' => $replier->getKey(),
            'replied_at' => now(),
        ]);

        return $review;
    }
}
