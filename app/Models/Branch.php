<?php

namespace App\Models;

use App\Concerns\Auditable;
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
    use Auditable, HasFactory, SoftDeletes;

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
     * What this branch holds, across all of its warehouses (P1-E4-T1).
     *
     * `branch_id` is denormalised onto `stocks` precisely so this question can
     * be answered without joining through `warehouses`.
     *
     * @return HasMany<Stock, $this>
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    /**
     * Every recorded change to this branch's stock (P1-E4-T1).
     *
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Orders this branch is the processing branch for (P1-E7-T1, BR-5).
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
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
     * The number of staff accounts stationed at this branch.
     *
     * Reuses the `users_count` of a `withCount(['users'])` listing when it is
     * there, so drawing the branch table does not cost one query per row.
     */
    public function staffCount(): int
    {
        $counted = $this->getAttribute('users_count');

        if ($counted !== null) {
            return (int) $counted;
        }

        return $this->relationLoaded('users') ? $this->users->count() : $this->users()->count();
    }

    /**
     * Whether the branch may be deleted.
     *
     * One predicate for the controller's guard and for the capability flag the
     * branch screens read, so the interface can never offer a deletion the
     * server is going to refuse (BUG-05).
     */
    public function isDeletable(): bool
    {
        return $this->deletionBlockedReason() === null;
    }

    /**
     * Why this branch may not be deleted, or null when it may be.
     *
     * The storefront needs its fallback branch to exist (ADR-017), and a branch
     * that still has staff would leave their accounts pointing at a branch
     * nobody can open.
     *
     * Orders are the hard stop (P1-E7-T1): BR-5 says every transaction names
     * the branch that fulfilled it, so deleting a branch that has sold
     * anything would erase the answer. `orders.branch_id` is
     * `restrictOnDelete` for the same reason — this is the readable half of
     * that constraint.
     */
    public function deletionBlockedReason(): ?string
    {
        if ($this->is_default) {
            return 'Cabang utama tidak dapat dihapus. Tunjuk cabang lain sebagai cabang utama terlebih dahulu.';
        }

        $staffCount = $this->staffCount();

        if ($staffCount > 0) {
            return "Cabang ini masih memiliki {$staffCount} akun staf. Pindahkan atau hapus akun tersebut terlebih dahulu.";
        }

        $transferCount = StockTransfer::acrossBranches()->visibleToBranch($this->getKey())->count();

        if ($transferCount > 0) {
            return "Cabang ini tercatat di {$transferCount} transfer stok. Dokumen transfer menjelaskan pergerakan stok di dua cabang, jadi cabang ini tidak dapat dihapus. Nonaktifkan cabang bila tidak dipakai lagi.";
        }

        $orderCount = Order::acrossBranches()->withTrashed()->where('branch_id', $this->getKey())->count();

        if ($orderCount > 0) {
            return "Cabang ini memproses {$orderCount} pesanan. Riwayat pesanan harus tetap menyebut cabang pemrosesnya (BR-5), jadi cabang ini tidak dapat dihapus.";
        }

        return null;
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
