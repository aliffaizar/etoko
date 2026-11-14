<?php

namespace App\Policies;

use App\Models\Review;
use App\Models\User;

/**
 * Who may read, moderate and answer reviews (P2-E8-T3, ADR-068).
 *
 * Follows docs/04-rbac.md, "Moderasi review": central staff show or hide a
 * review, while a branch admin only answers reviews of its own branch. Both
 * sides need `reviews.moderate`; the central side also needs a central actor,
 * the same rule as the other marketing screens. Which branch's reviews a
 * branch admin reaches is the `BelongsToBranch` scope's job, so this policy
 * does not repeat it.
 */
class ReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('reviews.moderate');
    }

    public function moderate(User $user, Review $review): bool
    {
        return $user->can('reviews.moderate') && $user->isCentral();
    }

    public function reply(User $user, Review $review): bool
    {
        return $user->can('reviews.moderate');
    }
}
