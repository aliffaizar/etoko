<?php

namespace App\Services\Shipping\Drivers;

use App\Enums\ShipmentStatus;
use App\Exceptions\ShippingFailedException;
use App\Models\Courier;
use App\Models\CourierService;
use App\Models\Shipment;
use App\Services\Shipping\Data\ShipmentResult;
use App\Services\Shipping\Data\ShippingQuote;
use App\Services\Shipping\Data\ShippingQuoteRequest;
use App\Services\Shipping\Data\TrackingEvent;
use App\Services\Shipping\Data\TrackingResult;
use App\Services\Shipping\ShippingProvider;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Biteship, behind the same interface as the shop's own rate table
 * (P2-E2-T6, ADR-004).
 *
 * An aggregator rather than a single courier, which is why it was chosen over
 * wiring each expedition company separately: one set of credentials and one
 * request shape answers for JNE, J&T, SiCepat and the rest, and the courier
 * row's `config.biteship.courier_code` is the only place our vocabulary meets
 * theirs.
 *
 * **Quotes are filtered against the shop's own services, not merged with
 * them.** Biteship answers with every service it sells for a courier, and a
 * service the shop has no `courier_services` row for cannot be offered: there
 * would be nothing to snapshot onto the shipment and nothing to track it with.
 * So the answer is matched on service code and the rest is dropped — a quote
 * the customer cannot choose is worse than one that was never shown, because
 * they would choose it.
 *
 * **Destinations are sent as text.** Biteship accepts an area id or a
 * postcode-plus-address pair, and this sends the latter, because `addresses`
 * has no area ids to send (ADR-043). It is the weaker of the two lookups and
 * it is the one the data supports; when a region dataset lands,
 * `ShippingQuoteRequest::$destinationRegionId` is already carried through for
 * it.
 *
 * Nothing here is written to the database: this is a driver (see
 * `ShippingProvider`). The waybill number and the scans it comes back with are
 * persisted by `DispatchShipment` and `RecordShipmentTracking`, inside their
 * transactions.
 *
 * Testing: every call goes through the `Http` facade, so the integration tests
 * drive it with `Http::fake()` and no sandbox credentials. That is deliberate —
 * the project has no Biteship key yet, and a driver that can only be tested
 * against a live sandbox is a driver that is not tested. The courier is
 * therefore **seeded inactive**: an active courier whose provider refuses
 * every quote is a checkout that fails at the last step. See `SHIP-03`.
 */
final class BiteshipProvider implements ShippingProvider
{
    /**
     * Biteship's own status vocabulary, and what each value means here.
     *
     * The list is longer than `ShipmentStatus` because an aggregator reports
     * the courier's internal stages — "allocated", "picking_up", "dropping_off"
     * — and this application has one question: has it left, is it moving, did
     * it arrive. Collapsing them here is what keeps that dialect out of
     * `RecordShipmentTracking` and out of the customer's timeline.
     *
     * @var array<string, ShipmentStatus>
     */
    private const STATUS_MAP = [
        'confirmed' => ShipmentStatus::Pending,
        'scheduled' => ShipmentStatus::Pending,
        'allocated' => ShipmentStatus::Pending,
        'picking_up' => ShipmentStatus::Pending,
        'picked' => ShipmentStatus::PickedUp,
        'dropping_off' => ShipmentStatus::InTransit,
        'on_process' => ShipmentStatus::InTransit,
        'in_transit' => ShipmentStatus::InTransit,
        'delivered' => ShipmentStatus::Delivered,
        'courier_not_found' => ShipmentStatus::Failed,
        'rejected' => ShipmentStatus::Failed,
        'disposed' => ShipmentStatus::Failed,
        'returned' => ShipmentStatus::Returned,
        'cancelled' => ShipmentStatus::Returned,
        'on_hold' => ShipmentStatus::Failed,
    ];

    /**
     * @return array<int, ShippingQuote>
     */
    public function quote(Courier $courier, ShippingQuoteRequest $request): array
    {
        if (! $request->hasDestination()) {
            return [];
        }

        /** @var Collection<int, CourierService> $services */
        $services = $courier->services()->active()->ordered()->get();

        if ($services->isEmpty()) {
            return [];
        }

        $response = $this->send(
            fn (PendingRequest $http): Response => $http->post($this->url('/v1/rates/couriers'), [
                'origin_postal_code' => $request->origin->postal_code,
                'origin_address' => $this->originAddress($request),
                'destination_postal_code' => $request->postalCode,
                'destination_address' => $request->destinationLabel(),
                'couriers' => $courier->providerCode(),
                'items' => [[
                    'name' => 'Pesanan',
                    'value' => max(0, $request->itemValue),
                    'quantity' => 1,
                    'weight' => $request->weightGram,
                ]],
            ]),
        );

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if (! $response->successful()) {
            throw ShippingFailedException::providerRefused($this->errorDetail($body, $response->status()));
        }

        return $this->quotesFrom($body, $services, $courier);
    }

    /**
     * Book the parcel and come back with a waybill number.
     *
     * The order is read through the shipment because Biteship wants both ends
     * of the journey and the goods between them, and the shipment alone knows
     * none of that.
     */
    public function createShipment(Shipment $shipment): ShipmentResult
    {
        $shipment->loadMissing(['order.branch', 'order.items', 'courier', 'courierService']);
        $order = $shipment->order;
        $courier = $shipment->courier;

        if (! $courier instanceof Courier) {
            throw ShippingFailedException::unsupported(
                "Kurir \"{$shipment->courier_name}\" sudah tidak terdaftar, jadi pengirimannya tidak dapat dipesan otomatis.",
            );
        }

        $response = $this->send(
            fn (PendingRequest $http): Response => $http->post($this->url('/v1/orders'), [
                'shipper_contact_name' => $order->branch->name,
                'shipper_contact_phone' => $order->branch->phone ?? '-',
                'origin_contact_name' => $order->branch->name,
                'origin_contact_phone' => $order->branch->phone ?? '-',
                'origin_address' => $order->branch->address_line,
                'origin_postal_code' => $order->branch->postal_code,
                'destination_contact_name' => $order->shipping_recipient ?? $order->customer_name,
                'destination_contact_phone' => $order->shipping_phone ?? '-',
                'destination_address' => $order->shipping_address_line,
                'destination_postal_code' => $order->shipping_postal_code,
                'destination_note' => $order->customer_note,
                'courier_company' => $courier->providerCode(),
                'courier_type' => $shipment->courierService->code ?? '',
                'delivery_type' => 'now',
                'reference_id' => $order->order_number,
                'items' => $this->orderItemsPayload($shipment),
            ]),
        );

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if (! $response->successful()) {
            throw ShippingFailedException::providerRefused($this->errorDetail($body, $response->status()));
        }

        $waybill = $this->stringAt($body, ['courier', 'waybill_id']);
        $bookingId = $this->stringFrom($body, 'id');

        if ($waybill === null) {
            // Biteship issues the waybill when the courier accepts, which can
            // be a few minutes after the booking. The booking id is enough to
            // find it again, and the sweep will fill the number in — so this
            // is not a failure, it is a parcel booked and not yet labelled.
            if ($bookingId === null) {
                throw ShippingFailedException::providerRefused('balasan tidak memuat nomor resi maupun id pesanan.');
            }

            return ShipmentResult::manual(null);
        }

        return ShipmentResult::booked($waybill, $bookingId, $body);
    }

    /**
     * Ask where the parcel is.
     *
     * Keyed on Biteship's own booking id when there is one, and on the waybill
     * plus courier code otherwise: a parcel booked through them is tracked by
     * their id, while one whose number was typed in by the branch can still be
     * tracked through their public endpoint.
     */
    public function track(Shipment $shipment): TrackingResult
    {
        $shipment->loadMissing('courier');

        $path = $shipment->external_id !== null
            ? '/v1/trackings/'.rawurlencode($shipment->external_id)
            : sprintf(
                '/v1/trackings/%s/couriers/%s',
                rawurlencode((string) $shipment->tracking_number),
                rawurlencode($shipment->courier?->providerCode() ?? ''),
            );

        if ($shipment->tracking_number === null && $shipment->external_id === null) {
            throw ShippingFailedException::unsupported(
                'Pengiriman ini belum punya nomor resi, jadi posisinya belum dapat ditanyakan.',
            );
        }

        $response = $this->send(fn (PendingRequest $http): Response => $http->get($this->url($path)));

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if (! $response->successful()) {
            throw ShippingFailedException::providerRefused($this->errorDetail($body, $response->status()));
        }

        $status = $this->statusFor($this->stringFrom($body, 'status'));

        if (! $status instanceof ShipmentStatus) {
            throw ShippingFailedException::providerRefused('status pengiriman tidak dikenali.');
        }

        return new TrackingResult(
            status: $status,
            events: $this->eventsFrom($body, $status),
            receivedBy: $this->stringFrom($body, 'destination_contact_name'),
            rawPayload: $body,
        );
    }

    /**
     * Turn Biteship's `pricing` list into quotes for the shop's own services.
     *
     * Matched on service code, case-insensitively, because the shop types
     * "REG" and an aggregator may answer "reg". A row the shop does not have
     * is dropped; a row whose price is missing is dropped too, since a quote
     * with no number is not a quote.
     *
     * @param  array<string, mixed>  $body
     * @param  Collection<int, CourierService>  $services
     * @return array<int, ShippingQuote>
     */
    private function quotesFrom(array $body, $services, Courier $courier): array
    {
        $pricing = $body['pricing'] ?? null;

        if (! is_array($pricing)) {
            return [];
        }

        $byCode = $services->keyBy(fn (CourierService $service): string => mb_strtolower($service->code));
        $quotes = [];

        foreach ($pricing as $row) {
            if (! is_array($row)) {
                continue;
            }

            $code = $this->stringFrom($row, 'courier_service_code');
            $service = $code === null ? null : $byCode->get(mb_strtolower($code));

            if (! $service instanceof CourierService) {
                continue;
            }

            $price = $row['price'] ?? null;

            if (! is_int($price) && ! (is_string($price) && is_numeric($price)) && ! is_float($price)) {
                continue;
            }

            $service->setRelation('courier', $courier);

            $quotes[] = ShippingQuote::forService(
                service: $service,
                cost: (int) round((float) $price),
                etdMinDay: $this->daysFrom($row, 'shipment_duration_range', 'min'),
                etdMaxDay: $this->daysFrom($row, 'shipment_duration_range', 'max'),
                rawPayload: $row,
            );
        }

        return $quotes;
    }

    /**
     * Biteship's `history` list as tracking events.
     *
     * A payload with no history at all still produces one event — the status
     * the aggregator reports, stamped now — because a parcel whose timeline is
     * empty would show the customer nothing at all while the shipment's own
     * status said it had moved.
     *
     * @param  array<string, mixed>  $body
     * @return array<int, TrackingEvent>
     */
    private function eventsFrom(array $body, ShipmentStatus $current): array
    {
        $history = $body['history'] ?? null;

        if (! is_array($history) || $history === []) {
            return [new TrackingEvent($current, $current->label(), now())];
        }

        $events = [];

        foreach ($history as $row) {
            if (! is_array($row)) {
                continue;
            }

            $status = $this->statusFor($this->stringFrom($row, 'status'));
            $updatedAt = $this->stringFrom($row, 'updated_at');

            if (! $status instanceof ShipmentStatus) {
                continue;
            }

            $events[] = new TrackingEvent(
                status: $status,
                description: $this->stringFrom($row, 'note') ?? $status->label(),
                // A scan with no timestamp is placed now rather than dropped:
                // the courier said it, and "we do not know when" is better
                // kept than thrown away.
                eventAt: $updatedAt === null ? now() : $this->parseMoment($updatedAt),
                location: $this->stringFrom($row, 'service_type'),
            );
        }

        return $events;
    }

    /**
     * A moment from the provider, or now when it cannot be read.
     *
     * Never throws: a malformed timestamp in one of forty scans must not lose
     * the other thirty-nine.
     */
    private function parseMoment(string $value): CarbonInterface
    {
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return now();
        }
    }

    /**
     * What a Biteship status means here, or null when it means nothing.
     */
    private function statusFor(?string $status): ?ShipmentStatus
    {
        if ($status === null) {
            return null;
        }

        return self::STATUS_MAP[mb_strtolower(trim($status))] ?? null;
    }

    /**
     * The origin, as one line of text for an aggregator that wants one.
     */
    private function originAddress(ShippingQuoteRequest $request): string
    {
        $branch = $request->origin;

        return implode(', ', array_values(array_filter(
            [$branch->address_line, $branch->city_name, $branch->province_name],
            static fn (?string $part): bool => is_string($part) && trim($part) !== '',
        )));
    }

    /**
     * The order's lines, as Biteship wants to see them.
     *
     * The weight is split across the lines the order actually has, so a
     * two-item parcel is declared as two items: aggregators price and insure
     * per item, and declaring one 4 kg "Pesanan" would misdeclare a parcel the
     * courier opens.
     *
     * @return array<int, array<string, mixed>>
     */
    private function orderItemsPayload(Shipment $shipment): array
    {
        $items = $shipment->order->items;

        if ($items->isEmpty()) {
            return [[
                'name' => 'Pesanan '.$shipment->order->order_number,
                'value' => $shipment->order->subtotal,
                'quantity' => 1,
                'weight' => max(1, $shipment->weight_gram),
            ]];
        }

        return $items->map(fn ($item): array => [
            'name' => $item->product_name,
            'sku' => $item->sku,
            'value' => $item->unit_price,
            'quantity' => $item->quantity,
            'weight' => max(1, $item->weight_gram),
        ])->values()->all();
    }

    /**
     * A day count out of a nested range, or null when the provider gives none.
     *
     * @param  array<string, mixed>  $row
     */
    private function daysFrom(array $row, string $key, string $bound): ?int
    {
        $range = $row[$key] ?? null;

        if (! is_array($range)) {
            return null;
        }

        $value = $range[$bound] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Send a request, turning a dead connection into a sentence.
     *
     * @param  callable(PendingRequest): Response  $send
     *
     * @throws ShippingFailedException
     */
    private function send(callable $send): Response
    {
        $key = $this->apiKey();

        if ($key === null) {
            throw ShippingFailedException::unsupported(
                'Ekspedisi Biteship belum dikonfigurasi (BITESHIP_API_KEY kosong). Pilih kurir lain.',
            );
        }

        $http = Http::withHeaders(['authorization' => $key])
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.biteship.timeout', 15));

        try {
            return $send($http);
        } catch (ConnectionException $unreachable) {
            Log::warning('Biteship could not be reached.', ['message' => $unreachable->getMessage()]);

            throw ShippingFailedException::providerUnreachable();
        }
    }

    /**
     * The provider's own words for a refusal, or the status code when it has
     * none.
     *
     * @param  array<string, mixed>  $body
     */
    private function errorDetail(array $body, int $httpStatus): string
    {
        $message = $this->stringFrom($body, 'error') ?? $this->stringFrom($body, 'message');

        return $message === null ? "HTTP {$httpStatus}." : rtrim($message, '.').'.';
    }

    private function apiKey(): ?string
    {
        $key = config('services.biteship.api_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.biteship.base_url'), '/').$path;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function stringFrom(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  array<int, string>  $path
     */
    private function stringAt(array $payload, array $path): ?string
    {
        $cursor = $payload;

        foreach ($path as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return is_string($cursor) && trim($cursor) !== '' ? trim($cursor) : null;
    }
}
