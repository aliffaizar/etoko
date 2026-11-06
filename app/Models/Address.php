<?php

namespace App\Models;

use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's saved delivery address (P1-E6-T1).
 *
 * Region FK columns (`province_id`, `city_id`, `district_id`) are nullable and
 * use `nullOnDelete` so a stale region row does not prevent deletion. The name
 * snapshot columns (`province_name` etc.) are the source of truth for display;
 * the FK columns are for future distance / shipping calculation.
 *
 * Exactly one address per user may be `is_default = true`. The controller and
 * `AddressController::store/update` enforce this by clearing the flag on
 * sibling rows inside a transaction.
 *
 * @property int $id
 * @property int $user_id
 * @property string $label
 * @property string $recipient_name
 * @property string $phone
 * @property int|null $province_id
 * @property int|null $city_id
 * @property int|null $district_id
 * @property string $province_name
 * @property string $city_name
 * @property string $district_name
 * @property string $postal_code
 * @property string $address_line
 * @property float|null $latitude
 * @property float|null $longitude
 * @property bool $is_default
 * @property string|null $notes
 * @property-read User $user
 */
#[Fillable([
    'user_id', 'label', 'recipient_name', 'phone',
    'province_id', 'city_id', 'district_id',
    'province_name', 'city_name', 'district_name',
    'postal_code', 'address_line', 'latitude', 'longitude',
    'is_default', 'notes',
])]
class Address extends Model
{
    /** @use HasFactory<AddressFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
