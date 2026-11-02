<?php

namespace App\Models;

use App\Enums\BranchStatus;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $phone
 * @property string|null $email
 * @property int|null $province_id
 * @property int|null $city_id
 * @property int|null $district_id
 * @property string|null $province_name
 * @property string|null $city_name
 * @property string|null $district_name
 * @property string|null $postal_code
 * @property string $address_line
 * @property string|null $latitude
 * @property string|null $longitude
 * @property BranchStatus $status
 * @property bool $supports_delivery
 * @property bool $supports_pickup
 * @property bool $is_default
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'code', 'name', 'slug', 'description', 'phone', 'email',
    'province_id', 'city_id', 'district_id',
    'province_name', 'city_name', 'district_name', 'postal_code',
    'address_line', 'latitude', 'longitude',
    'status', 'supports_delivery', 'supports_pickup', 'is_default', 'sort_order',
])]
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BranchStatus::class,
            'supports_delivery' => 'boolean',
            'supports_pickup' => 'boolean',
            'is_default' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Warehouse, $this>
     */
    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    /**
     * @return HasMany<BranchOperatingHour, $this>
     */
    public function operatingHours(): HasMany
    {
        return $this->hasMany(BranchOperatingHour::class);
    }

    /**
     * @return BelongsTo<Region, $this>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'province_id');
    }

    /**
     * @return BelongsTo<Region, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'city_id');
    }

    /**
     * @return BelongsTo<Region, $this>
     */
    public function district(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'district_id');
    }

    /**
     * The warehouse stock moves through unless another one is chosen.
     *
     * @return HasMany<Warehouse, $this>
     */
    public function defaultWarehouse(): HasMany
    {
        return $this->warehouses()->where('is_default', true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('status', BranchStatus::Active);
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
