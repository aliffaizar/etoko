<?php

namespace App\Enums;

enum FulfillmentType: string
{
    case Delivery = 'delivery';
    case Pickup = 'pickup';

    public function label(): string
    {
        return match ($this) {
            self::Delivery => 'Dikirim',
            self::Pickup => 'Ambil di Cabang',
        };
    }

    public function requiresShipping(): bool
    {
        return $this === self::Delivery;
    }
}
