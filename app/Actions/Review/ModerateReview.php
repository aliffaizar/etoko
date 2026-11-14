<?php

namespace App\Actions\Review;

use App\Enums\ReviewStatus;
use App\Models\Review;
use Illuminate\Support\Facades\DB;

/**
 * Shows or hides a review (P2-E8-T3, ADR-068).
 *
 * Moderation can be revised: a review rejected by mistake can be shown later,
 * and the rating follows either way, since `RefreshProductRating` recomputes
 * from what is on show.
 */
final class ModerateReview
{
    public function __construct(private readonly RefreshProductRating $refreshProductRating) {}

    public function handle(Review $review, ReviewStatus $status): Review
    {
        return DB::transaction(function () use ($review, $status): Review {
            $review->update(['status' => $status]);

            $this->refreshProductRating->handle($review->product_id);

            return $review;
        });
    }
}
