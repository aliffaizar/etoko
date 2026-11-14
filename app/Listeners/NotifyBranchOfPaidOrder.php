<?php

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Models\User;
use App\Notifications\OrderAwaitingBranchNotification;
use App\Services\Branch\BranchContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the branch that has to pack an order that it has been paid for
 * (P2-E6-T4).
 *
 * `paid` is the one moment the order becomes the branch's job: before it there
 * is nothing to pack, and after it the branch itself is the one moving it.
 * The audience is the branch's active staff holding `orders.update_status` or
 * `orders.pack` — the people who press "Proses" on the order screen or "Mulai
 * kemas" on the packing list (NTF-02, ADR-062) — following
 * `NotifyStockTransferParties`:
 * central staff are not notified (their order listing shows every branch),
 * and whoever confirmed the payment is not told about their own click.
 */
class NotifyBranchOfPaidOrder
{
    public function __construct(private BranchContext $context) {}

    public function handle(OrderStatusChanged $event): void
    {
        if ($event->to !== OrderStatus::Paid) {
            return;
        }

        $recipients = $this->staffOf($event->order->branch_id)
            ->reject(fn (User $user): bool => $user->is($event->actor))
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new OrderAwaitingBranchNotification($event->order));
    }

    /**
     * The branch's active staff who move orders along or pack them.
     *
     * The restriction is lifted around the lookup, as in `NotifyLowStock`:
     * the payment may be confirmed by central staff or by nobody at all (the
     * gateway status check), and the order's branch is the only thing that
     * should decide who hears.
     *
     * @return Collection<int, User>
     */
    private function staffOf(int $branchId): Collection
    {
        return $this->context->withoutRestriction(
            fn (): Collection => User::query()
                ->where('branch_id', $branchId)
                ->where('is_active', true)
                ->get()
                ->filter(fn (User $user): bool => $user->can('orders.update_status') || $user->can('orders.pack'))
                ->toBase(),
        );
    }
}
