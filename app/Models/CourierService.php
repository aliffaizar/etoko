<?php

namespace App\Models;

use App\Concerns\Auditable;
use Database\Factories\CourierServiceFactory;
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
 * One service level of a courier — "JNE REG", "JNE YES" (P2-E2-T1).
 *
 * What the customer picks at checkout is a service, never a courier: "ship
 * with JNE" says neither what it costs nor when it lands, and those are the
 * two things the choice is about.
 *
 * @property int $id
 * @property int $courier_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int|null $etd_min_day
 * @property int|null $etd_max_day
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Courier $courier
 * @property-read Collection<int, ShippingRate> $rates
 */
#[Fillable([
    'courier_id', 'code', 'name', 'description',
    'etd_min_day', 'etd_max_day', 'is_active', 'sort_order',
])]
class CourierService extends Model
{
    /** @use HasFactory<CourierServiceFactory> */
    use Auditable, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'etd_min_day' => 'integer',
            'etd_max_day' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Courier, $this>
     */
    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    /**
     * @return HasMany<ShippingRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(ShippingRate::class);
    }

    /**
     * How the shop names this service to a customer: "JNE — Reguler".
     *
     * Reads a loaded `courier` relation when the caller has one; without it,
     * the service's own name stands alone rather than costing a query per row
     * in a quote listing.
     */
    public function displayName(): string
    {
        return $this->relationLoaded('courier')
            ? "{$this->courier->name} — {$this->name}"
            : $this->name;
    }

    /**
     * The delivery promise in words, or null when the courier makes none.
     *
     * A single-day promise reads as "1 hari" rather than "1–1 hari", because
     * nobody writes the second one.
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
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
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
        return 'id';
    }
}
