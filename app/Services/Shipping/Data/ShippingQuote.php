<?php

namespace App\Services\Shipping\Data;

use App\Models\CourierService;

/**
 * One way of sending a parcel, and what it costs (P2-E2-T2, ADR-004).
 *
 * What a driver answers `quote()` with, and the only shape the checkout screen,
 * `PlaceOrder` and the dispatch form know about — which is what lets a price
 * read out of a rate table and a price quoted by an aggregator arrive at the
 * same radio button.
 *
 * `courierServiceId` is the identity the customer's choice travels back as,
 * and it is a **local** id on purpose: an aggregator quoting a service the shop
 * has no row for cannot be chosen, because there would be nothing to snapshot
 * onto the shipment and nothing to track it with later. `ShippingManager`
 * drops those quotes rather than inventing rows for them.
 *
 * `etdMinDay` / `etdMaxDay` are both nullable because a courier that promises
 * nothing is the ordinary case for same-day services, and a zero would read as
 * "arrives today" — a claim the shop would be making on the courier's behalf.
 */
final class ShippingQuote
{
    /**
     * @param  array<string, mixed>|null  $rawPayload  The provider's own answer, kept for a dispute.
     */
    public function __construct(
        public readonly int $courierServiceId,
        public readonly int $courierId,
        public readonly string $courierName,
        public readonly string $courierCode,
        public readonly string $serviceName,
        public readonly string $serviceCode,
        public readonly int $cost,
        public readonly ?int $etdMinDay = null,
        public readonly ?int $etdMaxDay = null,
        public readonly ?string $description = null,
        public readonly ?array $rawPayload = null,
    ) {}

    /**
     * A quote for one of the shop's own services, at a price a driver worked
     * out.
     *
     * Everything descriptive comes off the service and its courier, so the two
     * drivers cannot disagree about what "JNE REG" is called; only the cost
     * and, where a provider quotes its own, the estimate differ.
     *
     * The `courier` relation is loaded here rather than assumed: a quote is
     * built in a loop over services, and a missing eager load would otherwise
     * be a query per row that nobody notices until the rate table grows.
     *
     * @param  array<string, mixed>|null  $rawPayload
     */
    public static function forService(
        CourierService $service,
        int $cost,
        ?int $etdMinDay = null,
        ?int $etdMaxDay = null,
        ?array $rawPayload = null,
    ): self {
        $courier = $service->courier;

        return new self(
            courierServiceId: $service->getKey(),
            courierId: $courier->getKey(),
            courierName: $courier->name,
            courierCode: $courier->code,
            serviceName: $service->name,
            serviceCode: $service->code,
            cost: $cost,
            etdMinDay: $etdMinDay ?? $service->etd_min_day,
            etdMaxDay: $etdMaxDay ?? $service->etd_max_day,
            description: $service->description,
            rawPayload: $rawPayload,
        );
    }

    /**
     * How the shop names this option to a customer: "JNE — Reguler".
     */
    public function displayName(): string
    {
        return "{$this->courierName} — {$this->serviceName}";
    }

    /**
     * The delivery promise in words, or null when the courier makes none.
     */
    public function estimateLabel(): ?string
    {
        return match (true) {
            $this->etdMinDay !== null && $this->etdMaxDay !== null && $this->etdMinDay !== $this->etdMaxDay => "{$this->etdMinDay}–{$this->etdMaxDay} hari",
            $this->etdMinDay !== null => "{$this->etdMinDay} hari",
            $this->etdMaxDay !== null => "{$this->etdMaxDay} hari",
            default => null,
        };
    }

    /**
     * The columns this quote fills in on a shipment row.
     *
     * The snapshots come from here rather than being read off the service
     * again at write time, so the row records the quote that was *accepted*
     * and not the catalogue as it stands when it is saved (ADR-007).
     *
     * @return array<string, mixed>
     */
    public function shipmentAttributes(): array
    {
        return [
            'courier_id' => $this->courierId,
            'courier_service_id' => $this->courierServiceId,
            'courier_name' => $this->courierName,
            'service_name' => $this->serviceName,
            'cost' => $this->cost,
            'etd_min_day' => $this->etdMinDay,
            'etd_max_day' => $this->etdMaxDay,
        ];
    }

    /**
     * How a quote reaches the screens.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'courier_service_id' => $this->courierServiceId,
            'courier_id' => $this->courierId,
            'courier_name' => $this->courierName,
            'courier_code' => $this->courierCode,
            'service_name' => $this->serviceName,
            'service_code' => $this->serviceCode,
            'display_name' => $this->displayName(),
            'cost' => $this->cost,
            'etd_min_day' => $this->etdMinDay,
            'etd_max_day' => $this->etdMaxDay,
            'estimate_label' => $this->estimateLabel(),
            'description' => $this->description,
        ];
    }
}
