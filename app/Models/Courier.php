<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\ShippingDriver;
use Database\Factories\CourierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * An expedition company (P2-E2-T1).
 *
 * Company-wide reference data with no branch of its own, the same choice as
 * `PaymentMethod`: "JNE" is the same arrangement everywhere, and where it may
 * be used is a rule in `shipping_rates` rather than an owner on this row.
 *
 * `Auditable`, because switching a courier off or repointing it at a different
 * driver changes what every customer is offered and what every parcel costs —
 * a decision somebody has to be able to trace (BR-9).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $logo_path
 * @property ShippingDriver $driver
 * @property bool $is_active
 * @property int $sort_order
 * @property array<string, mixed>|null $config
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, CourierService> $services
 * @property-read Collection<int, Shipment> $shipments
 */
#[Fillable(['code', 'name', 'logo_path', 'driver', 'is_active', 'sort_order', 'config'])]
class Courier extends Model
{
    /** @use HasFactory<CourierFactory> */
    use Auditable, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver' => ShippingDriver::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'config' => 'array',
        ];
    }

    /**
     * @return HasMany<CourierService, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(CourierService::class);
    }

    /**
     * @return HasMany<Shipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /**
     * This courier's code in an aggregator's own vocabulary (`P2-E2-T6`).
     *
     * Falls back to our own `code`, which is right far more often than it is
     * wrong — most aggregators use the same slugs the industry does — while
     * still letting a courier whose code differs say so without a schema
     * change.
     */
    public function providerCode(): string
    {
        $configured = $this->config['biteship']['courier_code'] ?? null;

        return is_string($configured) && $configured !== '' ? $configured : $this->code;
    }

    /**
     * The courier's own tracking page for a waybill, when it publishes one.
     *
     * A template with `{tracking_number}` in it, so the customer's tracking
     * page can offer "check at the courier" without this application knowing a
     * URL per company.
     */
    public function trackingUrlFor(?string $trackingNumber): ?string
    {
        $template = $this->config['tracking_url'] ?? null;

        if (! is_string($template) || $template === '' || $trackingNumber === null || $trackingNumber === '') {
            return null;
        }

        return str_replace('{tracking_number}', rawurlencode($trackingNumber), $template);
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path === null ? null : Storage::disk('public')->url($this->logo_path);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The order couriers are offered in: the shop's own preference first, then
     * alphabetically so two couriers sharing a `sort_order` never swap places
     * between page loads.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function ordered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }
}
