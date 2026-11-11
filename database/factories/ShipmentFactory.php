<?php

namespace Database\Factories;

use App\Enums\ShipmentStatus;
use App\Models\CourierService;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shipment>
 */
class ShipmentFactory extends Factory
{
    /**
     * A parcel the branch has not handed over yet — what `CreateShipment`
     * writes at checkout.
     *
     * `branch_id`, the two courier ids and both snapshots are derived from the
     * rows this shipment points at, through closures over `$attributes`: a
     * caller who passes only `forOrder()` still gets a row whose branch agrees
     * with that order, and `shipments.branch_id` **must** agree with
     * `orders.branch_id` or the branch scope would hide a parcel from the
     * branch that has to send it (ADR-038).
     *
     * `shipped_at`, `delivered_at` and the order transitions that go with them
     * are **not** faked: handing goods to a courier moves the order, which
     * only `DispatchShipment` and `RecordShipmentTracking` may do. A test that
     * needs a dispatched parcel dispatches one.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'branch_id' => fn (array $attributes): int => Order::withoutGlobalScopes()
                ->whereKey($attributes['order_id'])
                ->sole()
                ->branch_id,
            'courier_service_id' => CourierService::factory(),
            'courier_id' => fn (array $attributes): int => CourierService::query()
                ->whereKey($attributes['courier_service_id'])
                ->sole()
                ->courier_id,
            'courier_name' => fn (array $attributes): string => CourierService::query()
                ->with('courier')
                ->whereKey($attributes['courier_service_id'])
                ->sole()
                ->courier
                ->name,
            'service_name' => fn (array $attributes): string => CourierService::query()
                ->whereKey($attributes['courier_service_id'])
                ->sole()
                ->name,
            'tracking_number' => null,
            'cost' => fn (array $attributes): int => Order::withoutGlobalScopes()
                ->whereKey($attributes['order_id'])
                ->sole()
                ->shipping_cost,
            'weight_gram' => fn (array $attributes): int => Order::withoutGlobalScopes()
                ->whereKey($attributes['order_id'])
                ->sole()
                ->total_weight_gram,
            'status' => ShipmentStatus::Pending,
            'etd_min_day' => 2,
            'etd_max_day' => 3,
            'external_id' => null,
            'shipped_at' => null,
            'delivered_at' => null,
            'tracking_synced_at' => null,
            'failure_reason' => null,
            'notes' => null,
            'dispatched_by' => null,
            'raw_payload' => null,
        ];
    }

    public function forOrder(Order $order): static
    {
        return $this->state([
            'order_id' => $order->getKey(),
            'branch_id' => $order->branch_id,
            'cost' => $order->shipping_cost,
            'weight_gram' => $order->total_weight_gram,
        ]);
    }

    public function forService(CourierService $service): static
    {
        return $this->state([
            'courier_service_id' => $service->getKey(),
            'courier_id' => $service->courier_id,
            'courier_name' => $service->courier->name,
            'service_name' => $service->name,
            'etd_min_day' => $service->etd_min_day,
            'etd_max_day' => $service->etd_max_day,
        ]);
    }

    /**
     * A parcel on its way, as a *record* only.
     *
     * The order it belongs to is left exactly as it is, so this state is for
     * tests about reading shipments, never about what dispatching one does.
     */
    public function dispatched(string $trackingNumber = 'JNE000123456789'): static
    {
        return $this->state([
            'status' => ShipmentStatus::PickedUp,
            'tracking_number' => $trackingNumber,
            'shipped_at' => now(),
        ]);
    }

    public function inTransit(string $trackingNumber = 'JNE000123456789'): static
    {
        return $this->state([
            'status' => ShipmentStatus::InTransit,
            'tracking_number' => $trackingNumber,
            'shipped_at' => now()->subDay(),
        ]);
    }

    public function delivered(string $trackingNumber = 'JNE000123456789'): static
    {
        return $this->state([
            'status' => ShipmentStatus::Delivered,
            'tracking_number' => $trackingNumber,
            'shipped_at' => now()->subDays(2),
            'delivered_at' => now(),
        ]);
    }

    public function returned(string $reason = 'Alamat tidak ditemukan.'): static
    {
        return $this->state([
            'status' => ShipmentStatus::Returned,
            'tracking_number' => 'JNE000123456789',
            'shipped_at' => now()->subDays(3),
            'failure_reason' => $reason,
        ]);
    }
}
