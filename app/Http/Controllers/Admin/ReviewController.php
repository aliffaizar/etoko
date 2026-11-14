<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Review\ModerateReview;
use App\Actions\Review\ReplyToReview;
use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexReviewRequest;
use App\Http\Requests\Admin\ReplyReviewRequest;
use App\Models\Review;
use App\Models\User;
use App\Support\ReviewPresenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The review queue (P2-E8-T3, ADR-068).
 *
 * One screen for central and branch staff. `Review` carries the branch scope,
 * so a branch admin's queue is already its own and another branch's review id
 * answers 404 before `ReviewPolicy` is asked (ADR-027). Showing and hiding is
 * central; answering is anyone who may moderate in that branch.
 */
class ReviewController extends Controller
{
    private const PER_PAGE = 20;

    public function index(IndexReviewRequest $request): Response
    {
        $this->authorize('viewAny', Review::class);

        $status = $request->status();

        /** @var User $viewer */
        $viewer = $request->user();

        return Inertia::render('admin/reviews/index', [
            'reviews' => $this->paginated($status, $viewer),
            'filters' => ['status' => $status->value ?? ''],
            'options' => ['statuses' => ReviewStatus::options()],
        ]);
    }

    public function approve(Review $review, ModerateReview $moderateReview): RedirectResponse
    {
        $this->authorize('moderate', $review);

        $moderateReview->handle($review, ReviewStatus::Approved);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ulasan ditampilkan.']);

        return back();
    }

    public function reject(Review $review, ModerateReview $moderateReview): RedirectResponse
    {
        $this->authorize('moderate', $review);

        $moderateReview->handle($review, ReviewStatus::Rejected);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ulasan disembunyikan.']);

        return back();
    }

    public function reply(ReplyReviewRequest $request, Review $review, ReplyToReview $replyToReview): RedirectResponse
    {
        $this->authorize('reply', $review);

        /** @var User $replier */
        $replier = $request->user();
        $replyToReview->handle($review, (string) $request->validated('reply'), $replier);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Balasan tersimpan.']);

        return back();
    }

    /**
     * @return LengthAwarePaginator<int, mixed>
     */
    private function paginated(?ReviewStatus $status, User $viewer): LengthAwarePaginator
    {
        return Review::query()
            ->with(['product:id,name', 'user:id,name', 'images', 'repliedBy:id,name', 'branch:id,name'])
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Review $review): array => ReviewPresenter::adminRow($review, $viewer));
    }
}
