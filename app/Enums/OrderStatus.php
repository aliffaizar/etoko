<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Processing = 'processing';
    case ReadyForPickup = 'ready_for_pickup';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Menunggu Pembayaran',
            self::Paid => 'Sudah Dibayar',
            self::Processing => 'Diproses',
            self::ReadyForPickup => 'Siap Diambil',
            self::Shipped => 'Dikirim',
            self::Delivered => 'Terkirim',
            self::Completed => 'Selesai',
            self::Cancelled => 'Dibatalkan',
            self::Expired => 'Kedaluwarsa',
            self::Refunded => 'Dana Dikembalikan',
            self::PartiallyRefunded => 'Dana Dikembalikan Sebagian',
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
            self::PendingPayment => [self::Paid, self::Cancelled, self::Expired],
            self::Paid => [self::Processing, self::Cancelled, self::Refunded],
            self::Processing => [self::Shipped, self::ReadyForPickup, self::Cancelled, self::Refunded],
            self::ReadyForPickup => [self::Completed, self::Cancelled, self::Refunded],
            self::Shipped => [self::Delivered, self::Cancelled, self::Refunded],
            self::Delivered => [self::Completed, self::Refunded, self::PartiallyRefunded],
            self::Completed => [self::Refunded, self::PartiallyRefunded],
            self::Cancelled, self::Expired, self::Refunded, self::PartiallyRefunded => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), strict: true);
    }

    /**
     * Whether the order is finished and no longer occupies reserved stock.
     */
    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Whether reserved stock should be released when the order reaches this status.
     */
    public function releasesReservedStock(): bool
    {
        return in_array($this, [self::Cancelled, self::Expired], strict: true);
    }

    /**
     * Statuses that still await action from the branch.
     *
     * @return array<int, self>
     */
    public static function openStatuses(): array
    {
        return [self::PendingPayment, self::Paid, self::Processing, self::ReadyForPickup, self::Shipped];
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
