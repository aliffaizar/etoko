<?php

namespace App\Exceptions;

use App\Models\Branch;
use RuntimeException;

/**
 * Checkout was refused, with something the customer can act on (P1-E7-T5).
 *
 * `PlaceOrder` re-checks everything the cart assumed — the branch still
 * trades, the products are still sold, the prices have not gone up, the stock
 * is still there — and throws this rather than placing an order that is wrong
 * in a way nobody notices until picking.
 *
 * `problems` lists the specific lines at fault, so the checkout screen can say
 * *which* product is the reason instead of "something went wrong". It is empty
 * when the refusal is about the order as a whole.
 */
class CheckoutFailedException extends RuntimeException
{
    /**
     * @param  array<int, string>  $problems
     */
    public function __construct(
        string $message,
        public readonly array $problems = [],
    ) {
        parent::__construct($message);
    }

    public static function emptyCart(): self
    {
        return new self('Keranjang Anda kosong, jadi tidak ada yang bisa dipesan.');
    }

    public static function branchNotTrading(Branch $branch): self
    {
        return new self("Cabang {$branch->name} sedang tidak melayani pesanan. Pilih cabang lain.");
    }

    public static function fulfillmentUnsupported(Branch $branch, string $label): self
    {
        return new self("Cabang {$branch->name} tidak melayani {$label}. Pilih cara pemenuhan lain.");
    }

    public static function addressRequired(): self
    {
        return new self('Pilih alamat pengiriman terlebih dahulu.');
    }

    /**
     * @param  array<int, string>  $problems
     */
    public static function itemsUnavailable(array $problems): self
    {
        return new self(
            'Beberapa produk di keranjang tidak dapat dipesan. Periksa kembali keranjang Anda.',
            $problems,
        );
    }

    /**
     * @param  array<int, string>  $problems
     */
    public static function pricesChanged(array $problems): self
    {
        return new self(
            'Harga beberapa produk berubah sejak dimasukkan ke keranjang. '
                .'Keranjang sudah diperbarui — periksa totalnya sebelum melanjutkan.',
            $problems,
        );
    }
}
