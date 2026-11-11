<?php

namespace App\Services\Shipping\Data;

use App\Models\Address;
use App\Models\Branch;
use App\Models\Order;

/**
 * Where a parcel is going, from where, and how heavy it is (P2-E2-T2).
 *
 * The sibling of `PaymentContext`, and it exists for the same reason: quoting
 * is asked in three places that are not the same shape — the checkout screen,
 * which has a cart and a chosen address; `PlaceOrder`, which has an address and
 * a priced basket; and the panel, which has a placed order — and all three must
 * come out with the same number, or a customer is shown one price and charged
 * another. So the rules are written against this, and all three build one.
 *
 * The destination is **three names plus a postal code**, not a region id
 * (ADR-043): `addresses` stores the province, city and district the customer
 * typed, and its three region foreign keys are never populated because the
 * application ships with no region dataset. `destinationRegionId` is carried
 * anyway, so a driver that *can* use one does, and so the manual rate table
 * starts preferring region matches the day a dataset lands.
 *
 * `weightGram` is the whole parcel. A weightless parcel is impossible rather
 * than free, so this clamps to one gram: a courier charges for a minimum
 * weight, and a zero would make the per-kilogram arithmetic quote the flat
 * price for a sack of cement.
 */
final class ShippingQuoteRequest
{
    public readonly int $weightGram;

    public function __construct(
        public readonly Branch $origin,
        int $weightGram,
        public readonly ?string $districtName = null,
        public readonly ?string $cityName = null,
        public readonly ?string $provinceName = null,
        public readonly ?string $postalCode = null,
        public readonly ?int $destinationRegionId = null,
        public readonly int $itemValue = 0,
    ) {
        $this->weightGram = max(1, $weightGram);
    }

    /**
     * A quote for a customer's saved address.
     *
     * The three ids are read off the address even though they are usually
     * null: an address that *does* carry them should be quoted precisely, and
     * the day a region dataset lands this starts working with no change here.
     */
    public static function forAddress(
        Branch $origin,
        Address $address,
        int $weightGram,
        int $itemValue = 0,
    ): self {
        return new self(
            origin: $origin,
            weightGram: $weightGram,
            districtName: $address->district_name,
            cityName: $address->city_name,
            provinceName: $address->province_name,
            postalCode: $address->postal_code,
            destinationRegionId: $address->district_id ?? $address->city_id ?? $address->province_id,
            itemValue: $itemValue,
        );
    }

    /**
     * A quote for a placed order, read from its address snapshot.
     *
     * The order is the authoritative shape: this is what the panel re-quotes
     * against when a branch picks a different courier at dispatch, so the
     * figure it offers is the figure the customer's own address produces.
     */
    public static function forOrder(Order $order): self
    {
        return new self(
            origin: $order->branch,
            weightGram: $order->total_weight_gram,
            districtName: $order->shipping_district_name,
            cityName: $order->shipping_city_name,
            provinceName: $order->shipping_province_name,
            postalCode: $order->shipping_postal_code,
            itemValue: $order->subtotal,
        );
    }

    /**
     * The destination as `ShippingRate::coversDestination()` reads it.
     *
     * @return array{district_name: string|null, city_name: string|null, province_name: string|null}
     */
    public function destinationNames(): array
    {
        return [
            'district_name' => $this->districtName,
            'city_name' => $this->cityName,
            'province_name' => $this->provinceName,
        ];
    }

    /**
     * Whether there is a destination to quote against at all.
     *
     * A pickup order has none, and neither has an order whose address snapshot
     * is empty. A driver asked to quote for nowhere would answer with the
     * catch-all rate, which is a price for a delivery nobody can make.
     */
    public function hasDestination(): bool
    {
        return $this->cityName !== null && trim($this->cityName) !== '';
    }

    /**
     * The destination in one line, for the sentence a refusal is written in.
     */
    public function destinationLabel(): string
    {
        $parts = array_values(array_filter(
            [$this->districtName, $this->cityName, $this->provinceName],
            static fn (?string $part): bool => is_string($part) && trim($part) !== '',
        ));

        return $parts === [] ? 'alamat tujuan' : implode(', ', $parts);
    }

    /**
     * The parcel's weight in whole kilograms, rounded up — how a courier
     * counts it.
     */
    public function chargeableKilograms(): int
    {
        return max(1, (int) ceil($this->weightGram / 1000));
    }
}
