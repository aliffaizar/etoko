<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\BelongsToBranch;
use App\Enums\ShipmentStatus;
use App\Enums\ShippingDriver;
use Database\Factories\ShipmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One dispatch of an order's goods (P2-E2-T1).
 *
 * Branch-scoped like every other transactional table (BR-3) through a
 * `branch_id` copied from the order (ADR-038), so another branch's shipment id
 * in the URL answers 404 rather than 403 (ADR-027).
 *
 * Status is only ever changed by the actions in `App\Actions\Shipping`, each of
 * which re-reads the row under its lock. Writing `status` directly skips the
 * order-level consequence: the transition to `shipped` or `delivered`, and the
 * timeline row that is the customer's whole view of where their parcel is.
 *
 * `courier_name` and `service_name` are snapshots (ADR-007). The `courier`
 * relation is for reading the *current* arrangement — which driver to ask for
 * new scans — and never for describing how a parcel was sent.
 *
 * @property int $id
 * @property int $order_id
 * @property int $branch_id
 * @property int|null $courier_id
 * @property int|null $courier_service_id
 * @property string $courier_name
 * @property string $service_name
 * @property string|null $tracking_number
 * @property int $cost
 * @property int $weight_gram
 * @property ShipmentStatus $status
 * @property int|null $etd_min_day
 * @property int|null $etd_max_day
 * @property string|null $external_id
 * @property Carbon|null $shipped_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $tracking_synced_at
 * @property string|null $failure_reason
 * @property string|null $notes
 * @property int|null $dispatched_by
 * @property array<string, mixed>|null $raw_payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order $order
 * @property-read Branch $branch
 * @property-read Courier|null $courier
 * @property-read CourierService|null $courierService
 * @property-read User|null $dispatchedBy
 * @property-read Collection<int, ShipmentTrackingEvent> $trackingEvents
 */
#[Fillable([
    'order_id', 'branch_id', 'courier_id', 'courier_service_id',
    'courier_name', 'service_name', 'tracking_number',
    'cost', 'weight_gram', 'status', 'etd_min_day', 'etd_max_day',
    'external_id', 'shipped_at', 'delivered_at', 'tracking_synced_at',
    'failure_reason', 'notes', 'dispatched_by', 'raw_payload',
])]
class Shipment extends Model
{
    /** @use HasFactory<ShipmentFactory> */
    use Auditable, BelongsToBranch, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'cost' => 'integer',
            'weight_gram' => 'integer',
            'etd_min_day' => 'integer',
            'etd_max_day' => 'integer',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'tracking_synced_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Courier, $this>
     */
    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    /**
     * @return BelongsTo<CourierService, $this>
     */
    public function courierService(): BelongsTo
    {
        return $this->belongsTo(CourierService::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    /**
     * What the courier reported, oldest first — the sequence it happened in.
     *
     * Ordered by `event_at` rather than by id, because a sweep learns about
     * scans in the order the courier lists them and not in the order they
     * happened: a backfilled 17:40 scan belongs at 17:40.
     *
     * @return HasMany<ShipmentTrackingEvent, $this>
     */
    public function trackingEvents(): HasMany
    {
        return $this->hasMany(ShipmentTrackingEvent::class)
            ->orderBy('event_at')
            ->orderBy('id');
    }

    /**
     * Which code to talk to about this parcel.
     *
     * Read through the `courier` relation rather than a snapshot, for the same
     * reason `PaymentManager` does: the snapshot describes how the parcel was
     * *sent*, while the driver is who to ask *now*. A courier whose row has
     * been deleted leaves no one to ask, which is what null says.
     */
    public function driver(): ?ShippingDriver
    {
        return $this->courier?->driver;
    }

    /**
     * Whether this parcel is still on its way.
     */
    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * Whether the branch has handed this parcel over yet.
     */
    public function isDispatched(): bool
    {
        return $this->status->hasLeftBranch();
    }

    /**
     * Whether `SyncShipmentTracking` has anybody to ask about this parcel.
     *
     * Three things have to hold, and each of them is a real case: the parcel
     * is still moving, it has a waybill to ask about, and its courier is
     * served by a driver that can answer. A manual courier fails the last one
     * — its timeline is what the branch typed.
     */
    public function isTrackable(): bool
    {
        return $this->isOpen()
            && $this->tracking_number !== null
            && ($this->driver()?->supportsRemoteTracking() ?? false);
    }

    /**
     * Why this parcel may not be dispatched, or null when it may be.
     *
     * One source for the endpoint's refusal and for the `can.dispatch` flag
     * the screens read, so a button is never offered for something the server
     * will turn down — the pattern `Branch::deletionBlockedReason()` set.
     */
    public function dispatchBlockedReason(): ?string
    {
        if ($this->isDispatched()) {
            return "Pengiriman ini sudah berstatus \"{$this->status->label()}\".";
        }

        return null;
    }

    /**
     * The courier's own tracking page for this waybill, when it publishes one.
     */
    public function externalTrackingUrl(): ?string
    {
        return $this->courier?->trackingUrlFor($this->tracking_number);
    }

    /**
     * The delivery promise recorded when the parcel was quoted.
     */
    public function estimateLabel(): ?string
    {
        $min = $this->etd_min_day;
        $max = $this->etd_max_day;

        return match (true) {
            $min !== null && $max !== null && $min !== $max => "{$min}–{$max} hari",
            $min !== null => "{$min} hari",
            $max !== null => "{$max} hari",
            default => null,
        };
    }

    /**
     * Parcels still on their way.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function open(Builder $query): Builder
    {
        return $query->whereIn('status', array_values(array_map(
            fn (ShipmentStatus $status): string => $status->value,
            array_filter(ShipmentStatus::cases(), fn (ShipmentStatus $status): bool => $status->isOpen()),
        )));
    }

    /**
     * Parcels the sweep can ask a courier about (`P2-E2-T6`).
     *
     * Narrowed in SQL to what can be narrowed in SQL — still moving, has a
     * waybill, left the branch — and the driver check stays in
     * `isTrackable()`, because which driver serves a courier is a column on
     * another table and a join to exclude a handful of rows buys nothing.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function awaitingTracking(Builder $query): Builder
    {
        return $query->open()
            ->whereNotNull('tracking_number')
            ->where('status', '!=', ShipmentStatus::Pending->value);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function latestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('id');
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
