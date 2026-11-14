<?php

use App\Actions\Review\ReplyToReview;
use App\Models\Review;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/*
 * The seller answers only a review on show (P2-E8-T3, ADR-068).
 */

it('records the answer and who gave it', function () {
    $replier = User::factory()->adminPusat()->create();
    $review = Review::factory()->create();

    app(ReplyToReview::class)->handle($review, 'Terima kasih ulasannya.', $replier);

    $review->refresh();

    expect($review->reply)->toBe('Terima kasih ulasannya.')
        ->and($review->replied_by)->toBe($replier->getKey())
        ->and($review->replied_at)->not->toBeNull();
});

it('refuses an answer to a review that is not on show', function () {
    $review = Review::factory()->pending()->create();

    app(ReplyToReview::class)->handle($review, 'Halo', User::factory()->adminPusat()->create());
})->throws(ValidationException::class);
