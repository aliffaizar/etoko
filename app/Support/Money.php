<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Helpers for the application's money representation.
 *
 * Every monetary value in this application is a whole number of rupiah stored in
 * an integer column (ADR-008). Percentage discounts are rounded down so a
 * discount can never exceed the amount it is applied to.
 */
final class Money
{
    /**
     * Format an amount for display, e.g. 1500000 becomes "Rp 1.500.000".
     */
    public static function format(int $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }

    /**
     * Calculate a percentage of an amount, rounded down to the nearest rupiah.
     */
    public static function percentage(int $amount, float $percent): int
    {
        if ($percent < 0 || $percent > 100) {
            throw new InvalidArgumentException("Persentase harus antara 0 dan 100, {$percent} diberikan.");
        }

        return (int) floor($amount * $percent / 100);
    }

    /**
     * Apply a percentage discount, never returning less than zero.
     */
    public static function subtractPercentage(int $amount, float $percent): int
    {
        return max(0, $amount - self::percentage($amount, $percent));
    }

    /**
     * Clamp a discount so it never exceeds the amount being discounted.
     */
    public static function cap(int $discount, int $amount): int
    {
        return max(0, min($discount, $amount));
    }
}
