<?php

namespace App\Exceptions;

use App\Models\Order;
use RuntimeException;

/**
 * An order was about to be sold without any stock held for it (HIGH-03).
 *
 * `CommitReservation` turns an order's holds into a sale. An order that has
 * hold rows, but none of them still `active`, is an order whose goods were
 * given back to the shelf while it was still waiting to be paid — and
 * committing it would mark it sold with nothing leaving `stocks` at all, which
 * is the one outcome BR-6 and ADR-024 exist to make impossible.
 *
 * Carrying on quietly is what let the expiry sweep's batching bug end as
 * unexplainable stock, so this is raised instead. It always means something
 * upstream went wrong; the order stays where it is until a human looks.
 */
class ReservationMissingException extends RuntimeException
{
    public function __construct(
        public readonly Order $order,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function for(Order $order): self
    {
        return new self(
            $order,
            "Pesanan {$order->order_number} tidak lagi menahan stok, jadi tidak bisa "
                .'ditandai terbayar. Periksa riwayat penahanan stok pesanan ini.',
        );
    }
}
