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
    | It is also, by construction, how long the customer has to pay: a payment
    | attempt's deadline is its order's hold (ADR-041), so this is the one
    | number behind both.
    |
    | One day (ADR-054). A virtual account is often paid hours after it is
    | issued, and the shorter window this used to be expired orders whose
    | customers were still on their way to pay. The cost is the other side of
    | the same number: an abandoned checkout now keeps its units off the shelf
    | for a day rather than a quarter of an hour.
    |
    */

    'reservation_minutes' => (int) env('SHOP_RESERVATION_MINUTES', 1440),

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
