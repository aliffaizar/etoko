<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\ShippingRateScope;
use Database\Factories\ShippingRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One rule of the manual driver's price list (P2-E2-T1, T2).
 *
 * Deliberately **not** branch-scoped, although it has an `origin_branch_id`:
 * a price list is reference data a central account maintains, and a rule with
 * a null origin is one that applies to every branch. `BelongsToBranch` would
 * make the null rows invisible to branch staff, which is the opposite of what
 * null means here.
 *
 * @property int $id
 * @property int|null $courier_service_id
 * @property int|null $origin_branch_id
 * @property int|null $destination_region_id
 * @property ShippingRateScope $destination_type
 * @property string|null $destination_name
 * @property int $flat_price
 * @property int $price_per_kg
 * @property int $min_weight_gram
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CourierService|null $courierService
 * @property-read Branch|null $originBranch
 * @property-read Region|null $destinationRegion
 */
#[Fillable([
    'courier_service_id', 'origin_branch_id', 'destination_region_id',
    'destination_type', 'destination_name',
    'flat_price', 'price_per_kg', 'min_weight_gram', 'is_active',
])]
class ShippingRate extends Model
{
    /** @use HasFactory<ShippingRateFactory> */
    use Auditable, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'destination_type' => ShippingRateScope::class,
            'flat_price' => 'integer',
            'price_per_kg' => 'integer',
            'min_weight_gram' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<CourierService, $this>
     */
    public function courierService(): BelongsTo
    {
        return $this->belongsTo(CourierService::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function originBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'origin_branch_id');
    }

    /**
     * @return BelongsTo<Region, $this>
     */
    public function destinationRegion(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'destination_region_id');
    }

    /**
     * What this rule charges to carry `$weightGram`.
     *
     * The flat price covers the first `min_weight_gram`, and each further
     * kilogram is charged whole: couriers round up, and rounding down here
     * would make the shop pay the difference on every heavy parcel. A rule
     * with no per-kilogram price is a flat rate at any weight, which is what a
     * same-city courier usually quotes.
     *
     * `min_weight_gram` is read through `max(1, …)` because a rule saved with
     * a zero minimum would otherwise divide by nothing; zero is also the only
     * value of that column that cannot mean anything useful.
     */
    public function priceFor(int $weightGram): int
    {
        $minimum = max(1, $this->min_weight_gram);

        if ($this->price_per_kg === 0 || $weightGram <= $minimum) {
            return $this->flat_price;
        }

        $extraKilograms = (int) ceil(($weightGram - $minimum) / 1000);

        return $this->flat_price + ($extraKilograms * $this->price_per_kg);
    }

    /**
     * Whether this rule covers a destination described by its names.
     *
     * The comparison is case- and whitespace-insensitive because the address
     * was **typed by the customer** (ADR-043): "Kota Bandung" and "kota
     * bandung " are the same place, and a rate table that disagreed would
     * silently fall through to the catch-all.
     *
     * A rule with a scope but no `destination_name` matches nothing rather
     * than everything. That is the safer of the two readings: a half-written
     * rule that quietly priced every destination at one city's rate would be
     * invisible until somebody compared an invoice with a tariff sheet.
     *
     * @param  array{district_name?: string|null, city_name?: string|null, province_name?: string|null}  $destination
     */
    public function coversDestination(array $destination): bool
    {
        if ($this->destination_type === ShippingRateScope::All) {
            return true;
        }

        $key = $this->destination_type->destinationNameKey();

        if ($key === null || $this->destination_name === null) {
            return false;
        }

        $candidate = $destination[$key] ?? null;

        if (! is_string($candidate) || trim($candidate) === '') {
            return false;
        }

        return self::normalise($candidate) === self::normalise($this->destination_name);
    }

    /**
     * A place name reduced to what two people typing it would agree on.
     */
    public static function normalise(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? $name));
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
     * The rules that could apply when shipping out of `$branchId`.
     *
     * Rules with a null origin are included, because null means "from
     * anywhere" — leaving them out would make a company-wide price list
     * invisible to every branch that has one of its own.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function shippableFrom(Builder $query, int $branchId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('origin_branch_id')
            ->orWhere('origin_branch_id', $branchId));
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
