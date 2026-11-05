<?php

namespace App\Listeners;

use App\Events\StockRanLow;
use App\Models\User;
use App\Notifications\LowStockNotification;
use App\Services\Branch\BranchContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the people who can do something about low stock (P1-E4-T9).
 *
 * Only the branch's own staff who may actually adjust stock — its admin and
 * warehouse staff. Three deliberate exclusions:
 *
 * - **Cashiers**, who hold `stocks.view` but not `stocks.adjust`: they sell
 *   stock, they do not reorder it.
 * - **Central staff**, who would otherwise receive every branch's low-stock
 *   alert and stop reading any of them. They see the same figures on the stock
 *   screen with a branch selector, which is where a central question about one
 *   branch's shelves actually starts.
 * - **Deactivated accounts**, who cannot sign in to read it.
 *
 * Not queued. The database channel is a single insert per recipient, and
 * keeping it synchronous means a developer or a small deployment sees
 * notifications without a worker running. If a branch ever generates enough
 * crossings for that to hurt, this is the one class to add `ShouldQueue` to.
 */
class NotifyLowStock
{
    public function __construct(private BranchContext $context) {}

    public function handle(StockRanLow $event): void
    {
        $recipients = $this->recipients($event->stock->branch_id);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new LowStockNotification($event->stock));
    }

    /**
     * The branch's staff who may act on the figure.
     *
     * `User` carries no branch global scope, so the narrowing is written out
     * here. The branch restriction is lifted around the lookup because the
     * event may be dispatched from a job or a central screen, where the
     * context is either absent or set to another branch — the branch id on the
     * stock row is the only thing that should decide who hears about it.
     *
     * @return Collection<int, User>
     */
    private function recipients(int $branchId): Collection
    {
        return $this->context->withoutRestriction(
            fn (): Collection => User::query()
                ->where('branch_id', $branchId)
                ->where('is_active', true)
                ->get()
                ->filter(fn (User $user): bool => $user->can('stocks.adjust'))
                ->values(),
        );
    }
}
