<?php

use App\Enums\ReviewStatus;
use App\Models\Branch;
use App\Models\Review;
use App\Models\User;

/*
 * The review queue (P2-E8-T3, ADR-068). Showing and hiding is central; a branch
 * admin may answer the reviews of its own branch and no other.
 */

it('redirects guests to the login page', function () {
    $this->get(route('admin.reviews.index'))->assertRedirect(route('login'));
});

it('lists reviews filtered by status', function () {
    Review::factory()->pending()->create();
    Review::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.reviews.index', ['status' => 'pending']))
        ->assertOk();
});

it('shows a review for central staff', function () {
    $review = Review::factory()->pending()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.reviews.approve', $review))
        ->assertRedirect();

    expect($review->fresh()->status)->toBe(ReviewStatus::Approved);
});

it('refuses a branch admin to show or hide a review', function () {
    $branch = Branch::factory()->default()->create();
    $review = Review::factory()->pending()->create(['branch_id' => $branch->getKey()]);
    $actor = User::factory()->adminCabang($branch)->create();

    $this->actingAs($actor)->post(route('admin.reviews.approve', $review))->assertForbidden();
    $this->actingAs($actor)->post(route('admin.reviews.reject', $review))->assertForbidden();

    expect($review->fresh()->status)->toBe(ReviewStatus::Pending);
});

it('lets a branch admin answer a review of its own branch', function () {
    $branch = Branch::factory()->default()->create();
    $review = Review::factory()->create(['branch_id' => $branch->getKey()]);

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->post(route('admin.reviews.reply', $review), ['reply' => 'Terima kasih.'])
        ->assertRedirect();

    expect($review->fresh()->reply)->toBe('Terima kasih.');
});

it('answers 404 to a branch admin for another branch\'s review', function () {
    $own = Branch::factory()->default()->create();
    $other = Branch::factory()->create();
    $review = Review::factory()->create(['branch_id' => $other->getKey()]);

    $this->actingAs(User::factory()->adminCabang($own)->create())
        ->post(route('admin.reviews.reply', $review), ['reply' => 'Halo'])
        ->assertNotFound();

    expect($review->fresh()->reply)->toBeNull();
});

it('refuses an answer to a review that is not on show', function () {
    $review = Review::factory()->pending()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.reviews.reply', $review), ['reply' => 'Halo'])
        ->assertSessionHasErrors('reply');
});

it('offers a branch admin only the answer, not showing or hiding', function () {
    $branch = Branch::factory()->default()->create();
    Review::factory()->pending()->create(['branch_id' => $branch->getKey()]);
    Review::factory()->create(['branch_id' => $branch->getKey()]);

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->get(route('admin.reviews.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('reviews.data', 2)
            ->where('reviews.data.0.can_approve', false)
            ->where('reviews.data.0.can_reject', false)
            ->where('reviews.data.1.can_reject', false)
            ->where('reviews.data.1.can_reply', true));
});

it('offers central staff every action the review is open to', function () {
    Review::factory()->pending()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.reviews.index'))
        ->assertInertia(fn ($page) => $page
            ->where('reviews.data.0.can_approve', true)
            ->where('reviews.data.0.can_reject', true)
            ->where('reviews.data.0.can_reply', false));
});
