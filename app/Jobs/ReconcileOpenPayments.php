<?php

namespace App\Jobs;

use App\Actions\Payment\ReconcilePayment;
use App\Models\Payment;
use App\Scopes\BranchScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Asks each provider about its open payment attempts (P2-E1-T6, ADR-053).
 *
 * The application has no webhook, so nothing tells it a payment arrived: it
 * has to ask. The customer's order page asks while they are looking at it,
 * and this job asks for everybody who is not — the customer who was given a
 * virtual account number, paid from their banking app an hour later and never
 * opened the shop again. Without it their order would sit in "menunggu
 * pembayaran" until the expiry sweep asked on its last day, and the branch
 * would not start packing goods that were paid for that morning.
 *
 * Runs every five minutes from `routes/console.php`. That is the longest a
 * paid order waits to reach the branch, and each run costs one Status API
 * call per open gateway attempt — with a one-day payment window those can
 * accumulate, which is why this is not every minute.
 *
 * Only attempts a provider can answer about are asked (`awaitingProvider`),
 * so a shop taking nothing but COD does no work here at all.
 *
 * It never expires anything. An attempt past its deadline is
 * `ExpireStockReservations`' to close, after asking the same question; this
 * job only ever moves an attempt forward on the provider's word.
 */
class ReconcileOpenPayments implements ShouldQueue
{
    use Queueable;

    /**
     * How many attempts are read from the database at a time.
     */
    private const CHUNK_SIZE = 100;

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('reconcile-open-payments'))->dontRelease()];
    }

    public function handle(ReconcilePayment $reconcilePayment): void
    {
        $settled = 0;
        $unanswered = 0;

        $attempts = Payment::withoutGlobalScope(BranchScope::class)
            ->awaitingProvider()
            ->with('paymentMethod')
            ->lazyById(self::CHUNK_SIZE);

        foreach ($attempts as $payment) {
            if (! $reconcilePayment->handle($payment)) {
                $unanswered++;

                continue;
            }

            if (! $payment->isOpen()) {
                $settled++;
            }
        }

        if ($settled > 0 || $unanswered > 0) {
            Log::info('Open payment reconciliation finished.', [
                'closed' => $settled,
                'unanswered' => $unanswered,
            ]);
        }
    }
}
