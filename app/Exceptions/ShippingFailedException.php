<?php

namespace App\Exceptions;

use App\Models\CourierService;
use App\Services\Shipping\Data\ShippingQuoteRequest;
use RuntimeException;

/**
 * A parcel that cannot be quoted, booked or tracked (P2-E2-T2).
 *
 * The message is written for the customer or the staff member who will read it
 * on a screen, in Indonesian, naming the thing to change — the pattern
 * `CheckoutFailedException` and `PaymentFailedException` set. Nothing here is
 * an "unexpected" failure: every case is a rule of the shop or a refusal from a
 * provider, both of which somebody has to be told about in words.
 *
 * `reason` is the machine-readable half, so a caller can decide which field to
 * attach the message to without matching on prose.
 */
class ShippingFailedException extends RuntimeException
{
    public const REASON_NO_SERVICE = 'no_service';

    public const REASON_SERVICE_UNAVAILABLE = 'service_unavailable';

    public const REASON_NO_DESTINATION = 'no_destination';

    public const REASON_PROVIDER = 'provider';

    public const REASON_UNSUPPORTED = 'unsupported';

    public private(set) string $reason = self::REASON_PROVIDER;

    private static function because(string $reason, string $message): self
    {
        $exception = new self($message);
        $exception->reason = $reason;

        return $exception;
    }

    /**
     * Nobody will carry a parcel to this address.
     *
     * Names the destination, because the customer can act on that — a city
     * nobody serves is a reason to collect at the branch, and "pengiriman
     * tidak tersedia" leaves them guessing whether the fault is the address,
     * the weight or the shop.
     */
    public static function noServiceAvailable(ShippingQuoteRequest $request): self
    {
        return self::because(
            self::REASON_NO_SERVICE,
            "Belum ada layanan pengiriman ke {$request->destinationLabel()}. "
                .'Pilih "Ambil di Cabang" atau gunakan alamat lain.',
        );
    }

    /**
     * A service the shop no longer offers, or no longer offers for this parcel.
     */
    public static function serviceUnavailable(CourierService $service): self
    {
        return self::because(
            self::REASON_SERVICE_UNAVAILABLE,
            "Layanan pengiriman \"{$service->displayName()}\" sedang tidak tersedia. Pilih layanan lain.",
        );
    }

    /**
     * A quote asked for with nowhere to send the parcel.
     */
    public static function destinationMissing(): self
    {
        return self::because(
            self::REASON_NO_DESTINATION,
            'Alamat pengiriman belum lengkap, jadi ongkos kirim belum dapat dihitung.',
        );
    }

    /**
     * A provider that refused, in its own words.
     */
    public static function providerRefused(string $detail): self
    {
        return self::because(
            self::REASON_PROVIDER,
            "Penyedia ekspedisi menolak permintaan ini: {$detail} Coba lagi atau pilih layanan lain.",
        );
    }

    /**
     * A provider that could not be reached at all.
     */
    public static function providerUnreachable(): self
    {
        return self::because(
            self::REASON_PROVIDER,
            'Penyedia ekspedisi tidak dapat dihubungi. Coba lagi beberapa saat atau pilih layanan lain.',
        );
    }

    /**
     * A driver that has no implementation, or a provider that cannot do what
     * is being asked of it.
     */
    public static function unsupported(string $detail): self
    {
        return self::because(self::REASON_UNSUPPORTED, $detail);
    }
}
