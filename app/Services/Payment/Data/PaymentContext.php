<?php

namespace App\Services\Payment\Data;

use App\Enums\FulfillmentType;
use App\Enums\OrderChannel;
use App\Models\Order;
use App\Support\Money;

/**
 * The facts a payment method is judged against (P2-E1-T7).
 *
 * Eligibility is asked in two places that are not the same shape — the
 * checkout screen, which has a cart and a quotation, and `InitiatePayment`,
 * which has a real order — and the rules must come out identical in both or a
 * customer would be offered COD and then refused it. So the rules are written
 * against this, and both callers build one.
 *
 * `amount` is the order's `grand_total`, **not** the amount a method would
 * charge: a ceiling of two million rupiah is a limit on the goods, not on the
 * goods plus somebody's fee. Checking the charge instead would make a method's
 * own fee push an order over its own ceiling.
 *
 * The destination is two text snapshots rather than a region id because that
 * is all an address is until `P2-E2-T3` gives regions a table. Both are null
 * for a pickup, which is correct and not missing data: nothing is being sent
 * anywhere, so a rule about where it goes cannot apply.
 */
final class PaymentContext
{
    public function __construct(
        public readonly int $branchId,
        public readonly FulfillmentType $fulfillmentType,
        public readonly int $amount,
        public readonly ?string $cityName = null,
        public readonly ?string $provinceName = null,
        public readonly OrderChannel $channel = OrderChannel::Online,
    ) {}

    /**
     * The context of a placed order — the authoritative one.
     *
     * `InitiatePayment` uses this, so the figures a method is judged against
     * are the ones the order actually carries rather than the ones the
     * checkout screen projected.
     */
    public static function forOrder(Order $order): self
    {
        return new self(
            branchId: $order->branch_id,
            fulfillmentType: $order->fulfillment_type,
            amount: $order->grand_total,
            cityName: $order->shipping_city_name,
            provinceName: $order->shipping_province_name,
            channel: $order->channel,
        );
    }

    /**
     * The amount as it reads on a screen, for the refusals that quote it.
     */
    public function formattedAmount(): string
    {
        return Money::format($this->amount);
    }
}
