<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Returns\FailRefund;
use App\Actions\Returns\ProcessRefund;
use App\Actions\Returns\SettleRefund;
use App\Enums\RefundMethod;
use App\Enums\RefundStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FailRefundRequest;
use App\Http\Requests\Admin\IndexRefundRequest;
use App\Http\Requests\Admin\SettleRefundRequest;
use App\Http\Requests\Admin\StoreRefundRequest;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Support\Money;
use App\Support\RefundListQuery;
use App\Support\RefundPresenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Money going back to the customer (P2-E4-T4).
 *
 * A listing of its own because the brief's admin menu has one
 * (`brief.md` §6, "Transaksi → Refund"), and because it answers a question the
 * return queue cannot: **which refunds has somebody promised and not yet
 * sent?** A manual transfer is opened `pending` and settled when the money has
 * actually moved, so that gap is a worklist.
 *
 * Reading and deciding are split along the line the matrix draws
 * (docs/04-rbac.md): a branch admin holds `returns.view` and sees what went
 * back against its own orders, while making a refund needs `refunds.process`
 * and is **central** (ADR-049). The listing is therefore visible to a branch
 * and its buttons are not.
 *
 * Refunds are opened from the **order** rather than from here — `store()`
 * takes an `{order}` — because an amount only means anything against the
 * order it is a share of, and `ProcessRefund` checks it against that order's
 * remaining total under its row lock. The form itself lives on the return
 * detail screen and on the order detail screen, which is where somebody is
 * standing when they decide.
 *
 * Because `SubstituteBindings` runs after `SetBranchContext` (ADR-027),
 * another branch's refund id in the URL answers **404** rather than 403.
 */
class RefundController extends Controller
{
    private const PER_PAGE = 20;

    public function index(IndexRefundRequest $request): Response
    {
        $this->authorize('viewAny', Refund::class);

        /** @var User $actor */
        $actor = $request->user();
        $filters = $request->filters();

        return Inertia::render('admin/refunds/index', [
            'refunds' => $this->paginated($filters),
            'filters' => $filters,
            'summary' => $this->summary(),
            'options' => [
                'statuses' => RefundStatus::options(),
                'methods' => RefundMethod::options(),
                'branches' => $actor->isCentral() ? $this->branchOptions() : [],
            ],
            'is_central' => $actor->isCentral(),
            'can' => ['process' => $actor->isCentral() && $actor->can('refunds.process')],
        ]);
    }

    /**
     * Open a refund against an order (P2-E4-T4).
     *
     * Everything that can refuse it does so with a sentence rather than a
     * status code: a gateway reversal the provider turned down, an amount
     * above what is left, a way of refunding the application cannot serve.
     * `ProcessRefund` throws `ValidationException` for all of them, so the
     * message lands on the field it belongs to.
     */
    public function store(
        StoreRefundRequest $request,
        Order $order,
        ProcessRefund $processRefund,
    ): RedirectResponse {
        $this->authorize('refund', $order);

        /** @var User $actor */
        $actor = $request->user();

        $refund = $processRefund->handle($order, $request->toRequest(), $actor);

        Inertia::flash('toast', [
            'type' => $refund->status === RefundStatus::Failed ? 'error' : 'success',
            'message' => $this->outcomeMessage($refund),
        ]);

        return back();
    }

    /**
     * Record that the money has actually gone back (P2-E4-T4).
     *
     * The button a staff member presses after making the transfer. This is
     * what moves `orders.payment_status`, so it is the moment the shop's books
     * say the customer has been paid.
     */
    public function settle(
        SettleRefundRequest $request,
        Refund $refund,
        SettleRefund $settleRefund,
    ): RedirectResponse {
        $this->authorize('process', $refund);

        /** @var User $actor */
        $actor = $request->user();

        if (! $settleRefund->handle($refund, $actor, $request->reference(), $request->notes())) {
            return back()->withErrors([
                'reference' => $refund->settlementBlockedReason()
                    ?? 'Refund ini sudah tidak dapat diubah.',
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Refund {$refund->code} tercatat sudah dikembalikan.",
        ]);

        return back();
    }

    /**
     * Record that the refund did not happen (P2-E4-T4).
     *
     * The amount goes straight back into what the order may still give back,
     * so this is never a dead end: a corrected account number and a second
     * refund is the normal path.
     */
    public function fail(
        FailRefundRequest $request,
        Refund $refund,
        FailRefund $failRefund,
    ): RedirectResponse {
        $this->authorize('process', $refund);

        /** @var User $actor */
        $actor = $request->user();

        $failRefund->handle($refund, $request->reason(), $actor);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Refund {$refund->code} ditandai gagal. Nominalnya kembali ke sisa dana pesanan.",
        ]);

        return back();
    }

    /**
     * What to tell the actor about the refund that was just opened.
     *
     * Built from what actually happened, because the next step genuinely
     * differs: a gateway that has already sent the money needs nothing, one
     * that merely accepted the reversal needs checking later, and a transfer
     * needs somebody to go and make it.
     */
    private function outcomeMessage(Refund $refund): string
    {
        $amount = Money::format($refund->amount);

        return match (true) {
            $refund->status === RefundStatus::Failed => "Refund {$refund->code} gagal: "
                .($refund->failure_reason ?? 'payment gateway menolak pengembalian dana.'),
            $refund->status === RefundStatus::Processed => "Refund {$refund->code} sebesar {$amount} "
                .'tercatat sudah dikembalikan.',
            $refund->method->isAutomatic() => "Refund {$refund->code} sebesar {$amount} sudah diminta ke "
                .'payment gateway dan sedang diproses.',
            default => "Refund {$refund->code} sebesar {$amount} dicatat. Lakukan transfernya, "
                .'lalu tandai sudah dikembalikan beserta nomor referensinya.',
        };
    }

    /**
     * @param  array{search: string, status: string, method: string, branch: string, from: string, until: string, sort: string, direction: string}  $filters
     * @return LengthAwarePaginator<int, mixed>
     */
    private function paginated(array $filters): LengthAwarePaginator
    {
        return RefundListQuery::from($filters)
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Refund $refund): array => RefundPresenter::row($refund));
    }

    /**
     * The counts above the listing: what still needs doing, and what has gone.
     *
     * `pending_amount` is the figure that matters and it is not a count: money
     * the shop has promised and not yet sent is a liability, and somebody has
     * to be able to see it without adding up a page of rows.
     *
     * @return array<string, int>
     */
    private function summary(): array
    {
        $byStatus = Refund::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'pending' => (int) ($byStatus[RefundStatus::Pending->value] ?? 0),
            'pending_amount' => (int) Refund::query()->open()->sum('amount'),
            'processed' => (int) ($byStatus[RefundStatus::Processed->value] ?? 0),
            'processed_amount' => (int) Refund::query()->settled()->sum('amount'),
            'failed' => (int) ($byStatus[RefundStatus::Failed->value] ?? 0),
        ];
    }

    /**
     * The branches a central actor may filter by.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function branchOptions(): array
    {
        return Branch::withoutGlobalScopes()
            ->active()
            ->ordered()
            ->get(['id', 'name'])
            ->map(fn (Branch $branch): array => [
                'value' => (string) $branch->id,
                'label' => $branch->name,
            ])
            ->values()
            ->all();
    }
}
