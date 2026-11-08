<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Payment\ExpirePayment;
use App\Actions\Payment\FailPayment;
use App\Actions\Payment\VerifyPayment;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentTransactionStatus;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\PaymentFailedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexPaymentRequest;
use App\Http\Requests\Admin\RejectPaymentRequest;
use App\Http\Requests\Admin\SettlePaymentRequest;
use App\Models\Branch;
use App\Models\Payment;
use App\Models\User;
use App\Support\PaymentListQuery;
use App\Support\PaymentPresenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The payment queue, for central and branch staff alike (P2-E1-T4).
 *
 * One screen for both, narrowed rather than duplicated (ADR-016): `Payment`
 * carries `BelongsToBranch` with a `branch_id` copied from the order
 * (ADR-038), so a branch account's queue is already its own and the branch
 * filter is simply of no use to them.
 *
 * The screen's job is one question — **which transfers am I waiting on?** —
 * and the `awaiting_proof` filter is the one that answers it: an open attempt
 * with a receipt attached needs a person to look at it, while an open attempt
 * without one is merely a customer who has not paid yet. The counts above the
 * listing are built around the same distinction.
 *
 * Three writes, each its own decision and each with its own refusal:
 * confirming the money arrived, turning a receipt down, and clearing a stale
 * attempt off the queue. All three go through the actions in
 * `App\Actions\Payment`, which take the order with them — a payment marked
 * paid whose stock was never committed would be goods sold without leaving the
 * ledger.
 *
 * Because `SubstituteBindings` runs after `SetBranchContext` (ADR-027),
 * another branch's payment id in the URL answers **404** rather than 403 — the
 * 404 is what keeps a branch from learning the payment exists at all.
 */
class PaymentController extends Controller
{
    private const PER_PAGE = 20;

    public function index(IndexPaymentRequest $request): Response
    {
        $this->authorize('viewAny', Payment::class);

        /** @var User $actor */
        $actor = $request->user();
        $filters = $request->filters();

        return Inertia::render('admin/payments/index', [
            'payments' => $this->paginated($filters),
            'filters' => $filters,
            'summary' => $this->summary(),
            'options' => [
                'statuses' => PaymentTransactionStatus::options(),
                'method_types' => PaymentMethodType::options(),
                // Branch accounts get no branch select: theirs is the only one
                // the queue can contain, so offering it would be a control
                // that cannot change anything.
                'branches' => $actor->isCentral() ? $this->branchOptions() : [],
            ],
            'is_central' => $actor->isCentral(),
            'can' => ['settle' => $actor->can('payments.verify')],
        ]);
    }

    public function show(Payment $payment): Response
    {
        $this->authorize('view', $payment);

        /** @var User $actor */
        $actor = request()->user();

        $payment->load([
            'order:id,order_number,customer_name,customer_email,customer_phone,status,payment_status,grand_total,branch_id',
            'branch:id,name',
            'paymentMethod',
            'verifiedBy:id,name',
        ]);

        return Inertia::render('admin/payments/show', [
            'payment' => PaymentPresenter::detail($payment, [
                'settle' => $actor->can('settle', $payment),
                'refund' => $actor->can('refund', $payment),
            ]),
            'blocked' => ['settle' => $payment->verificationBlockedReason()],
        ]);
    }

    /**
     * Confirm the money arrived (P2-E1-T4).
     *
     * This is the moment the order's stock becomes a sale, so the two failure
     * modes are told apart: an order whose shelf can no longer cover it
     * (`InsufficientStockException`) is a stock problem the branch has to
     * resolve, while an order that has expired or been cancelled
     * (`PaymentFailedException`) is money that has to go back — and the message
     * says which, because the next step is completely different.
     */
    public function verify(
        SettlePaymentRequest $request,
        Payment $payment,
        VerifyPayment $verifyPayment,
    ): RedirectResponse {
        $this->authorize('settle', $payment);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $verifyPayment->handleOrFail($payment, $actor, $request->note());
        } catch (PaymentFailedException $refused) {
            return back()->withErrors(['payment' => $refused->getMessage()]);
        } catch (InsufficientStockException $short) {
            return back()->withErrors([
                'payment' => 'Pembayaran tidak dapat diselesaikan karena stok cabang tidak lagi mencukupi: '
                    .$short->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Pembayaran {$payment->reference} dikonfirmasi lunas.",
        ]);

        return back();
    }

    /**
     * Turn a receipt down (P2-E1-T4).
     *
     * The order stays payable and its stock stays held: a rejected receipt is
     * one rejected receipt, and the customer may upload another or pick a
     * different method while the deadline lasts. The reason is required
     * because the customer reads it.
     */
    public function reject(
        RejectPaymentRequest $request,
        Payment $payment,
        FailPayment $failPayment,
    ): RedirectResponse {
        $this->authorize('settle', $payment);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $failPayment->handleOrFail($payment, $request->reason(), $actor);
        } catch (PaymentFailedException $refused) {
            return back()->withErrors(['reason' => $refused->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Pembayaran {$payment->reference} ditolak. Pelanggan dapat mencoba lagi "
                .'selama batas waktu pembayaran belum lewat.',
        ]);

        return back();
    }

    /**
     * Clear a stale attempt off the queue (P2-E1-T4).
     *
     * For the virtual account nobody used and the transfer nobody made. The
     * sweep in `ExpireStockReservations` does this automatically once the
     * deadline passes (ADR-041); this is for the attempt that has no deadline,
     * or for a staff member who would rather not wait for the next minute.
     */
    public function expire(
        SettlePaymentRequest $request,
        Payment $payment,
        ExpirePayment $expirePayment,
    ): RedirectResponse {
        $this->authorize('settle', $payment);

        if (! $expirePayment->handle($payment)) {
            return back()->withErrors([
                'payment' => $payment->verificationBlockedReason()
                    ?? 'Pembayaran ini sudah tidak dapat diubah.',
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Pembayaran {$payment->reference} ditandai kedaluwarsa.",
        ]);

        return back();
    }

    /**
     * @param  array{search: string, status: string, method_type: string, branch: string, awaiting_proof: string, from: string, until: string, sort: string, direction: string}  $filters
     * @return LengthAwarePaginator<int, mixed>
     */
    private function paginated(array $filters): LengthAwarePaginator
    {
        return PaymentListQuery::from($filters)
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Payment $payment): array => PaymentPresenter::row($payment));
    }

    /**
     * The counts above the listing: what still needs doing.
     *
     * Deliberately **not** affected by the filters below them — they answer
     * "what is waiting for me?", which a narrowed listing must not change the
     * answer to. Narrowed by branch for a branch account by the global scope,
     * like everything else here.
     *
     * `awaiting_verification` is the number that matters, and it is not a
     * status: it is the open attempts that have a receipt attached, which is
     * the queue a person actually works through.
     *
     * @return array<string, int>
     */
    private function summary(): array
    {
        $byStatus = Payment::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'awaiting_verification' => Payment::query()->open()->whereNotNull('proof_path')->count(),
            'open' => (int) ($byStatus[PaymentTransactionStatus::Pending->value] ?? 0),
            'overrun' => Payment::query()->overrun()->count(),
            'success' => (int) ($byStatus[PaymentTransactionStatus::Success->value] ?? 0),
            'failed' => (int) ($byStatus[PaymentTransactionStatus::Failed->value] ?? 0)
                + (int) ($byStatus[PaymentTransactionStatus::Expired->value] ?? 0),
        ];
    }

    /**
     * The branches a central actor may filter by.
     *
     * Read without the branch scope because this list is for a central actor
     * by definition; a branch account never receives it.
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
