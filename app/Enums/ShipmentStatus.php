<?php

namespace App\Enums;

/**
 * Where a parcel is (P2-E2-T1).
 *
 * Deliberately **not** the same thing as `OrderStatus`. An order's status is
 * the shop's obligation to the customer; this is the parcel's position, and
 * the two can legitimately disagree — a failed delivery leaves the order
 * `shipped` while the parcel sits at the depot. `RecordShipmentTracking` is
 * the one place that decides when a parcel's position moves the order.
 *
 * The transitions below are **not** a state machine of the same strictness as
 * `OrderStatus`: a courier may report its scans out of order, or skip one
 * entirely, and refusing its reality would leave the shipment lying. What they
 * express instead is forward progress, so a late "in transit" scan arriving
 * after "delivered" does not un-deliver a parcel.
 */
enum ShipmentStatus: string
{
    case Pending = 'pending';
    case PickedUp = 'picked_up';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu Pickup',
            self::PickedUp => 'Sudah Dipickup',
            self::InTransit => 'Dalam Perjalanan',
            self::Delivered => 'Terkirim',
            self::Failed => 'Gagal Kirim',
            self::Returned => 'Dikembalikan',
        };
    }

    /**
     * How far along the journey this status is.
     *
     * Used to decide whether a courier's scan is news or an echo of something
     * already recorded: a report that does not move the parcel forward is
     * appended to the timeline but does not rewrite `shipments.status`.
     *
     * `Failed` and `Returned` sit above `InTransit` and below `Delivered`: a
     * parcel that failed is further along than one in transit and a courier
     * that then delivers it on the second attempt is telling the truth, while
     * one that reports a new scan after a return is not moving it anywhere.
     */
    public function progress(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::PickedUp => 1,
            self::InTransit => 2,
            self::Failed => 3,
            self::Returned => 4,
            self::Delivered => 5,
        };
    }

    /**
     * Whether the parcel has left the branch.
     */
    public function hasLeftBranch(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * Whether this shipment is still a live dispatch.
     *
     * A returned parcel is not: re-sending it is a second shipment, exactly
     * as a second ask for money is a second `payments` row.
     */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Delivered, self::Returned], strict: true);
    }

    /**
     * Whether reaching this status means the order has been delivered.
     */
    public function completesDelivery(): bool
    {
        return $this === self::Delivered;
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

    /**
     * The statuses a branch may set by hand, in the order they happen.
     *
     * `Pending` is absent on purpose: a shipment starts there and moving a
     * parcel *back* to "not yet collected" is not something a courier does.
     *
     * @return array<int, self>
     */
    public static function manualTransitions(): array
    {
        return [self::PickedUp, self::InTransit, self::Delivered, self::Failed, self::Returned];
    }
}
