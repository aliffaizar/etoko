<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Checkout
    |--------------------------------------------------------------------------
    |
    | How long stock stays held for an order that has not been paid for
    | (P1-E7-T2, BR-6). The hold is what stops the same units being sold twice
    | while a customer finishes paying; `ExpireStockReservations` gives it back
    | once this has passed (P1-E7-T3).
    |
    | Fifteen minutes is the figure in docs/02-architecture.md §2. Shorter and
    | a slow bank transfer loses its goods; longer and an abandoned basket
    | keeps them off the shelf for an afternoon.
    |
    */

    'reservation_minutes' => (int) env('SHOP_RESERVATION_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Shipping
    |--------------------------------------------------------------------------
    |
    | A single flat delivery charge, used until a real courier rate lookup
    | arrives in `P2-E2-T6` (Biteship / RajaOngkir, which needs API keys the
    | project does not have yet). It is deliberately one number rather than a
    | guess at a rate table: a made-up table reads as real and would be trusted.
    |
    | Pickup orders are never charged it — nothing is shipped.
    |
    */

    'flat_shipping_cost' => (int) env('SHOP_FLAT_SHIPPING_COST', 15_000),

    /*
    |--------------------------------------------------------------------------
    | Tax
    |--------------------------------------------------------------------------
    |
    | Zero for now, and a setting rather than a literal so the one place that
    | decides it is here. Tax configuration per store belongs with store
    | identity in `P2-E9-T1`.
    |
    */

    'tax_percent' => (int) env('SHOP_TAX_PERCENT', 0),

];
