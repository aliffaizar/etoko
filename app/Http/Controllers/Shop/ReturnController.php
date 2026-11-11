<?php

namespace App\Http\Controllers\Shop;

use App\Actions\Returns\CancelReturn;
use App\Actions\Returns\RequestReturn;
use App\Enums\ReturnReason;
use App\Enums\ReturnType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\StoreReturnRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\User;
use App\Support\OrderPresenter;
use App\Support\ReturnPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * How a customer asks to send goods back (P2-E4-T2).
 *
 * Scoped by `user_id`, not by policy — the same arrangement as
 * `Shop\OrderController` and `Shop\PaymentController`, and for the same
 * reason: a shopper holds no `returns.*` permission, and letting one through
 * `OrderReturnPolicy` would mean deciding they may "view any return". Here the
 * relationship *is* the authorisation, so somebody else's order is **not
 * found** rather than refused.
 *
 * The request gets a screen of its own, unlike paying, because it asks for
 * things the order page has nowhere to put: a quantity per line, a reason, a
 * paragraph, and photos. What stays on the order page is the *result* — where
 * each request stands and whether it may still be withdrawn — because that is
 * what a customer comes back to look at.
 *
 * Both writes answer `back()` or a redirect with a flash, as the project's
 * Inertia convention requires (`BUG-09`).
 */
class ReturnController extends Controller
{
    /**
     * The form: every line of the order, with how many of it may still go
     * back.
     */
    public function create(Request $request, Order $order): Response
    {
        /** @var User $customer */
        $customer = $request->user();

        $this->assertOwnedBy($order, $customer);

        $blocked = $order->returnBlockedReason();

        if ($blocked !== null) {
            return Inertia::render('shop/return', [
                'order' => OrderPresenter::row($order->load('branch:id,name')),
                'lines' => [],
                'blocked_reason' => $blocked,
                'options' => ['types' => [], 'reasons' => []],
                'max_photos' => OrderReturn::MAX_PHOTOS,
            ]);
        }

        $order->load(['branch:id,name', 'items.returnItems.return']);

        return Inertia::render('shop/return', [
            'order' => OrderPresenter::row($order),
            'lines' => $order->items
                ->map(fn (OrderItem $item): array => ReturnPresenter::returnableLine($item))
                ->values()
                ->all(),
            'blocked_reason' => null,
            'options' => [
                'types' => ReturnType::options(),
                'reasons' => ReturnReason::options(),
            ],
            'max_photos' => OrderReturn::MAX_PHOTOS,
        ]);
    }

    /**
     * Submit the request.
     *
     * The photos are stored **before** the action runs, because a file has to
     * be on disk before its path can be written to a row — and they are
     * deleted again if the action refuses, so a rejected submission does not
     * leave five orphaned images per attempt.
     */
    public function store(
        StoreReturnRequest $request,
        Order $order,
        RequestReturn $requestReturn,
    ): RedirectResponse {
        /** @var User $customer */
        $customer = $request->user();

        $this->assertOwnedBy($order, $customer);

        $paths = $this->storePhotos($request, $order);

        try {
            $return = $requestReturn->handle($order, $customer, [
                ...$request->toRequest(),
                'photo_paths' => $paths,
            ]);
        } catch (ValidationException $refused) {
            $this->discardPhotos($paths);

            throw $refused;
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Pengajuan retur {$return->code} terkirim. Cabang akan memeriksanya dan "
                .'memberi tahu Anda langkah selanjutnya.',
        ]);

        return to_route('shop.account.orders.show', $order);
    }

    /**
     * Withdraw a request nobody has acted on yet.
     *
     * The return is reached **through the order** rather than bound from the
     * URL on its own, so an id belonging to somebody else's order is not found
     * instead of being refused.
     */
    public function cancel(
        Request $request,
        Order $order,
        OrderReturn $return,
        CancelReturn $cancelReturn,
    ): RedirectResponse {
        /** @var User $customer */
        $customer = $request->user();

        $this->assertOwnedBy($order, $customer);
        abort_unless($return->order_id === $order->getKey(), 404);

        $cancelReturn->handle($return, $customer);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Pengajuan retur {$return->code} dibatalkan.",
        ]);

        return back();
    }

    /**
     * Put the uploaded photos on the disk and return their paths.
     *
     * @return array<int, string>
     */
    private function storePhotos(StoreReturnRequest $request, Order $order): array
    {
        /** @var array<int, UploadedFile> $photos */
        $photos = $request->file('photos') ?? [];
        $paths = [];

        foreach ($photos as $photo) {
            $path = $photo->store(
                OrderReturn::PHOTO_DIRECTORY.'/'.$order->getKey(),
                OrderReturn::PHOTO_DISK,
            );

            if (is_string($path)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * Remove photos whose request was refused.
     *
     * @param  array<int, string>  $paths
     */
    private function discardPhotos(array $paths): void
    {
        if ($paths === []) {
            return;
        }

        Storage::disk(OrderReturn::PHOTO_DISK)->delete($paths);
    }

    /**
     * A customer may only reach their own order.
     *
     * 404 rather than 403: another customer's order must not be confirmed to
     * exist by the refusal that hides it.
     */
    private function assertOwnedBy(Order $order, User $customer): void
    {
        abort_unless($order->user_id === $customer->getKey(), 404);
    }
}
