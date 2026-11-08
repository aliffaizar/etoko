<?php

namespace App\Enums;

/**
 * What kind of payment a method is (P2-E1-T1).
 *
 * The `type` decides how a payment *behaves*, while `driver` decides who
 * carries it out: a virtual account can be issued by Midtrans or typed in by
 * hand, and both are still a virtual account to the customer. Keeping the two
 * apart is what lets `P2-E1-T5` add a gateway without touching a single screen
 * (ADR-004).
 *
 * Two of the six are settled by a human rather than by a transfer, and that is
 * the distinction most of the application cares about:
 *
 * - **`Cod`** — the courier collects on delivery, so the goods leave the shelf
 *   before the money arrives (ADR-040).
 * - **`Cash`** — the money is handed over at a counter, which only happens at
 *   the till. It is never offered in the storefront.
 */
enum PaymentMethodType: string
{
    case BankTransfer = 'bank_transfer';
    case VirtualAccount = 'virtual_account';
    case Qris = 'qris';
    case EWallet = 'e_wallet';
    case Cod = 'cod';
    case Cash = 'cash';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'Transfer Bank',
            self::VirtualAccount => 'Virtual Account',
            self::Qris => 'QRIS',
            self::EWallet => 'E-Wallet',
            self::Cod => 'Bayar di Tempat (COD)',
            self::Cash => 'Tunai',
        };
    }

    /**
     * Whether the customer proves the payment by uploading a receipt.
     *
     * Only a plain bank transfer works that way: the shop learns about the
     * money either from the customer's screenshot or from its own bank
     * statement, and a staff member decides which. Every other type is either
     * confirmed by the gateway (`P2-E1-T6`) or handed over in person.
     */
    public function expectsProofOfTransfer(): bool
    {
        return $this === self::BankTransfer;
    }

    /**
     * Whether the money is collected after the goods have gone out.
     *
     * A COD order is confirmed — and its stock committed — at the moment it is
     * placed, because that is when the branch starts packing it. Its
     * `payment_status` stays `pending` until somebody records the cash
     * (ADR-040).
     */
    public function isCollectedOnDelivery(): bool
    {
        return $this === self::Cod;
    }

    /**
     * Whether this type can be chosen in the storefront at all.
     *
     * `Cash` cannot: there is no counter on a website. It exists so POS
     * (Phase 3) records its takings against the same two tables.
     */
    public function isAvailableOnline(): bool
    {
        return $this !== self::Cash;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
