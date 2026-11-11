<?php

namespace App\Enums;

/**
 * How money goes back to the customer (P2-E4-T1, T4).
 *
 * Four ways, and only one of them is automatic: `gateway` asks the provider
 * that took the money to send it back, through `PaymentGateway::refund()`.
 * The other three are a person moving money and then recording that they did,
 * which is why `reference` and `account_info` exist on `refunds` — a transfer
 * nobody can trace is a refund the shop cannot prove it made.
 *
 * `store_credit` is accepted by the schema and **not** served by the
 * application: there is no wallet to credit until loyalty points arrive in
 * Phase 3, so `ProcessRefund` refuses it with a sentence rather than marking
 * money returned that went nowhere. Same choice as `ShippingDriver` makes for
 * `rajaongkir`.
 */
enum RefundMethod: string
{
    case Transfer = 'transfer';
    case Gateway = 'gateway';
    case Cash = 'cash';
    case StoreCredit = 'store_credit';

    public function label(): string
    {
        return match ($this) {
            self::Transfer => 'Transfer Bank',
            self::Gateway => 'Payment Gateway',
            self::Cash => 'Tunai di Cabang',
            self::StoreCredit => 'Saldo Toko',
        };
    }

    /**
     * Whether the provider that took the money is asked to send it back.
     */
    public function isAutomatic(): bool
    {
        return $this === self::Gateway;
    }

    /**
     * Whether a person has to move the money and then record that they did.
     */
    public function needsHuman(): bool
    {
        return in_array($this, [self::Transfer, self::Cash], strict: true);
    }

    /**
     * Whether the customer's bank details are needed to carry this out.
     *
     * Cash is handed over at a counter and a gateway refund goes back the way
     * it came, so neither has anywhere to send details to.
     */
    public function needsAccountInfo(): bool
    {
        return $this === self::Transfer;
    }

    /**
     * Why this way of refunding cannot be used, or null when it can.
     *
     * One source for `ProcessRefund`'s refusal and for the screen's option
     * list, so a method is never offered that the server will turn down.
     */
    public function unavailableReason(): ?string
    {
        if ($this === self::StoreCredit) {
            return 'Pengembalian sebagai saldo toko belum tersedia. Pilih transfer bank, tunai, '
                .'atau pengembalian lewat payment gateway.';
        }

        return null;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $method): array => ['value' => $method->value, 'label' => $method->label()],
            self::cases(),
        );
    }
}
