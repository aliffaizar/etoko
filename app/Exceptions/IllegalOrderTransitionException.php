<?php

namespace App\Exceptions;

use App\Enums\OrderStatus;
use App\Models\Order;
use RuntimeException;

/**
 * An order was asked to move to a status it cannot reach from where it is
 * (P1-E7-T7).
 *
 * Thrown by `TransitionOrderStatus` while it holds the order's row lock, so it
 * is the last word: a screen that offered the button a moment before the order
 * moved on lands here rather than writing a status the rest of the system
 * would then have to cope with.
 *
 * The state machine lives in `OrderStatus::allowedTransitions()`; the reason a
 * move is refused is always "that edge does not exist", never a guess.
 */
class IllegalOrderTransitionException extends RuntimeException
{
    public function __construct(
        public readonly Order $order,
        public readonly OrderStatus $from,
        public readonly OrderStatus $to,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function for(Order $order, OrderStatus $from, OrderStatus $to): self
    {
        $allowed = array_map(
            fn (OrderStatus $status): string => $status->label(),
            $from->allowedTransitions(),
        );

        $next = $allowed === []
            ? 'Status ini sudah final.'
            : 'Yang mungkin: '.implode(', ', $allowed).'.';

        return new self(
            $order,
            $from,
            $to,
            "Pesanan {$order->order_number} berstatus \"{$from->label()}\" tidak dapat berpindah ke "
                ."\"{$to->label()}\". {$next}",
        );
    }
}
