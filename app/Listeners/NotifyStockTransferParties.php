<?php

namespace App\Listeners;

use App\Enums\StockTransferStatus;
use App\Events\StockTransferAdvanced;
use App\Models\User;
use App\Notifications\StockTransferNotification;
use App\Services\Branch\BranchContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Tells whoever has to act next on a stock transfer (P2-E3-T6).
 *
 * Each step hands the transfer to someone, and that someone is told:
 *
 * | Step reached | Who hears | Why |
 * | --- | --- | --- |
 * | requested | origin staff with `stock_transfers.approve` | they decide |
 * | approved | origin `…ship` + destination `…request` | origin packs; destination knows it is coming |
 * | rejected | destination `…request` | their request is closed |
 * | in transit | destination `…receive` | they unload and count |
 * | received / partially | origin `…ship` | they sent it and should see any shortfall |
 * | cancelled | origin `…approve` | they may already be preparing it |
 *
 * As with low stock, central staff are not notified (they see every branch's
 * queue on the listing), deactivated accounts are skipped, and the person who
 * took the step is not told about their own click.
 */
class NotifyStockTransferParties
{
    public function __construct(private BranchContext $context) {}

    public function handle(StockTransferAdvanced $event): void
    {
        $transfer = $event->transfer;

        $recipients = collect($this->audience($transfer->status))
            ->flatMap(fn (array $audience): Collection => $this->staffOf(
                $audience[0] === 'from' ? $transfer->from_branch_id : $transfer->to_branch_id,
                $audience[1],
            ))
            ->reject(fn (User $user): bool => $user->is($event->actor))
            ->unique('id')
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new StockTransferNotification($transfer));
    }

    /**
     * Which end of the transfer, holding which permission, hears about a step.
     *
     * @return array<int, array{0: 'from'|'to', 1: string}>
     */
    private function audience(StockTransferStatus $status): array
    {
        return match ($status) {
            StockTransferStatus::Requested => [['from', 'stock_transfers.approve']],
            StockTransferStatus::Approved => [['from', 'stock_transfers.ship'], ['to', 'stock_transfers.request']],
            StockTransferStatus::Rejected => [['to', 'stock_transfers.request']],
            StockTransferStatus::InTransit => [['to', 'stock_transfers.receive']],
            StockTransferStatus::Received,
            StockTransferStatus::PartiallyReceived => [['from', 'stock_transfers.ship']],
            StockTransferStatus::Cancelled => [['from', 'stock_transfers.approve']],
        };
    }

    /**
     * The branch's active staff holding the permission.
     *
     * The restriction is lifted around the lookup for the reason
     * `NotifyLowStock` gives: the event may come from either end, and the
     * branch id named here is the only thing that should decide who hears.
     *
     * @return Collection<int, User>
     */
    private function staffOf(int $branchId, string $permission): Collection
    {
        return $this->context->withoutRestriction(
            fn (): Collection => User::query()
                ->where('branch_id', $branchId)
                ->where('is_active', true)
                ->get()
                ->filter(fn (User $user): bool => $user->can($permission))
                ->toBase(),
        );
    }
}
