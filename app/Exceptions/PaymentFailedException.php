<?php

namespace App\Exceptions;

use App\Models\Order;
use App\Models\PaymentMethod;
use RuntimeException;

/**
 * A payment that cannot be asked for, or cannot be asked for this way
 * (P2-E1-T2, T7).
 *
 * The message is written for the customer or the staff member who will read it
 * on a screen, in Indonesian, naming the thing to change — the pattern
 * `CheckoutFailedException` set. Nothing here is an "unexpected" failure: every
 * case is a rule of the shop or a refusal from a gateway, both of which somebody
 * has to be told about in words.
 *
 * `reason` is the machine-readable half, so a caller can decide which field to
 * attach the message to without matching on prose.
 */
class PaymentFailedException extends RuntimeException
{
    public const REASON_METHOD_UNAVAILABLE = 'method_unavailable';

    public const REASON_ORDER_NOT_PAYABLE = 'order_not_payable';

    public const REASON_ALREADY_SETTLED = 'already_settled';

    public const REASON_GATEWAY = 'gateway';

    public const REASON_UNSUPPORTED = 'unsupported';

    public private(set) string $reason = self::REASON_GATEWAY;

    private static function because(string $reason, string $message): self
    {
        $exception = new self($message);
        $exception->reason = $reason;

        return $exception;
    }

    public static function methodInactive(PaymentMethod $method): self
    {
        return self::because(
            self::REASON_METHOD_UNAVAILABLE,
            "Metode pembayaran \"{$method->name}\" sedang tidak tersedia. Pilih metode lain.",
        );
    }

    /**
     * A method the shop does not offer for this order — the branch, the
     * destination, the way of receiving the goods, or the amount (P2-E1-T7).
     */
    public static function methodNotEligible(PaymentMethod $method, string $because): self
    {
        return self::because(
            self::REASON_METHOD_UNAVAILABLE,
            "Metode pembayaran \"{$method->name}\" tidak dapat dipakai untuk pesanan ini: {$because}",
        );
    }

    /**
     * An order whose standing leaves nothing to pay.
     */
    public static function orderNotPayable(Order $order): self
    {
        return self::because(
            self::REASON_ORDER_NOT_PAYABLE,
            "Pesanan berstatus \"{$order->status->label()}\" tidak dapat dibayar lagi.",
        );
    }

    public static function alreadySettled(Order $order): self
    {
        return self::because(
            self::REASON_ALREADY_SETTLED,
            "Pesanan {$order->order_number} sudah lunas.",
        );
    }

    /**
     * A gateway that refused the charge, in its own words.
     */
    public static function gatewayRefused(string $detail): self
    {
        return self::because(
            self::REASON_GATEWAY,
            "Penyedia pembayaran menolak permintaan ini: {$detail} Coba lagi atau pilih metode lain.",
        );
    }

    /**
     * A gateway that could not be reached at all.
     */
    public static function gatewayUnreachable(): self
    {
        return self::because(
            self::REASON_GATEWAY,
            'Penyedia pembayaran tidak dapat dihubungi. Coba lagi beberapa saat atau pilih metode lain.',
        );
    }

    /**
     * A driver that has no implementation, or a gateway that cannot do what is
     * being asked of it.
     */
    public static function unsupported(string $detail): self
    {
        return self::because(self::REASON_UNSUPPORTED, $detail);
    }
}
