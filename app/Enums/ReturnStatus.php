<?php

namespace App\Enums;

/**
 * Where a return stands (P2-E4-T1).
 *
 * A state machine like `OrderStatus`, and for the same reason: the goods, the
 * stock figures and the money each move at a different point along it, so
 * which moves are legal has to be a definition rather than a guess made by
 * whichever screen is open. `App\Actions\Returns\TransitionReturnStatus` is
 * the only writer, and it checks every move against this enum.
 *
 * The sequence, and what each step means:
 *
 * - **`requested`** — the customer has asked. Nothing has moved; the parcel is
 *   still with them.
 * - **`approved`** — the branch has agreed to take the goods back. Still
 *   nothing has moved: an approval is a promise, not a receipt.
 * - **`received`** — the goods are physically back at the branch, counted,
 *   line by line, with a condition against each. This is the only step that
 *   touches stock (`sale_return_in`).
 * - **`completed`** — everything the return promised has happened: for a
 *   refund, the money has gone back.
 * - **`rejected`** — the branch will not take the goods back, with a reason
 *   the customer reads.
 * - **`cancelled`** — the customer withdrew the request before it was decided.
 *
 * `received` is deliberately **not** reachable from `requested`: goods that
 * turn up unannounced are a conversation, and accepting them without an
 * approval on the record would leave nobody who decided to.
 */
enum ReturnStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Received = 'received';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Diajukan',
            self::Approved => 'Disetujui',
            self::Rejected => 'Ditolak',
            self::Received => 'Barang Diterima',
            self::Completed => 'Selesai',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /**
     * The statuses this status may legally move to.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved => [self::Received, self::Rejected, self::Cancelled],
            self::Received => [self::Completed],
            self::Rejected, self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), strict: true);
    }

    /**
     * Whether the return is finished and will never move again.
     */
    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Whether the goods have come back and been counted.
     *
     * What `ProcessRefund` asks before paying money out: the shop pays for
     * goods it has, not for goods it has been promised.
     */
    public function goodsAreBack(): bool
    {
        return in_array($this, [self::Received, self::Completed], strict: true);
    }

    /**
     * Statuses that still await a decision or an action from the branch.
     *
     * @return array<int, self>
     */
    public static function openStatuses(): array
    {
        return [self::Requested, self::Approved, self::Received];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
