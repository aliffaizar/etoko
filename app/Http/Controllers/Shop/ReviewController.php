<?php

namespace App\Http\Controllers\Shop;

use App\Actions\Review\WriteReview;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\StoreReviewRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Throwable;

/**
 * A customer writes a review of a line they received (P2-E8-T3, ADR-068).
 *
 * Reached through the order, so another customer's order or line is not found
 * rather than refused. Eligibility is `WriteReview`'s: the order must be
 * delivered or completed and the review must not already exist.
 */
class ReviewController extends Controller
{
    public function store(
        StoreReviewRequest $request,
        Order $order,
        OrderItem $item,
        WriteReview $writeReview,
    ): RedirectResponse {
        /** @var User $customer */
        $customer = $request->user();

        abort_unless($order->user_id === $customer->getKey(), 404);
        abort_unless($item->order_id === $order->getKey(), 404);

        $paths = $this->storePhotos($request, $item);

        try {
            $review = $writeReview->handle($customer, $item, [
                'rating' => (int) $request->validated('rating'),
                'title' => $request->validated('title'),
                'comment' => $request->validated('comment'),
            ], $paths);
        } catch (Throwable $failed) {
            // Refused or failed, the review was not written, so its photos
            // belong to nothing.
            $this->discardPhotos($paths);

            throw $failed;
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Terima kasih! Ulasan Anda akan tampil setelah diperiksa.',
        ]);

        return to_route('shop.account.orders.show', $order);
    }

    /**
     * @return array<int, string>
     */
    private function storePhotos(StoreReviewRequest $request, OrderItem $item): array
    {
        /** @var array<int, UploadedFile> $photos */
        $photos = $request->file('photos') ?? [];
        $paths = [];

        foreach (array_slice($photos, 0, Review::MAX_IMAGES) as $photo) {
            $path = $photo->store(Review::IMAGE_DIRECTORY.'/'.$item->getKey(), Review::IMAGE_DISK);

            if (is_string($path)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function discardPhotos(array $paths): void
    {
        foreach ($paths as $path) {
            Storage::disk(Review::IMAGE_DISK)->delete($path);
        }
    }
}
