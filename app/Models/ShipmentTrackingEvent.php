<?php

namespace App\Models;

use App\Enums\ShipmentStatus;
use Database\Factories\ShipmentTrackingEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing a courier said about a parcel (P2-E2-T1, T5).
 *
 * Append-only. A row is never edited, because a courier correcting itself is a
 * new scan rather than a rewritten one, and the customer's tracking page is a
 * record of what was reported rather than a derivation of where the parcel is
 * now.
 *
 * Not `Auditable`: an append-only table *is* its own audit trail, and auditing
 * it would double every row a sweep writes.
 *
 * @property int $id
 * @property int $shipment_id
 * @property ShipmentStatus $status
 * @property string $description
 * @property string|null $location
 * @property string $source
 * @property Carbon $event_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Shipment $shipment
 */
#[Fillable(['shipment_id', 'status', 'description', 'location', 'source', 'event_at'])]
class ShipmentTrackingEvent extends Model
{
    /** @use HasFactory<ShipmentTrackingEventFactory> */
    use HasFactory;

    /** Reported by the courier, through a driver that asked it. */
    public const SOURCE_COURIER = 'courier';

    /** Typed in by branch staff. */
    public const SOURCE_BRANCH = 'branch';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'event_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * Who said this, in words the customer can read.
     */
    public function sourceLabel(): string
    {
        return $this->source === self::SOURCE_BRANCH ? 'Dicatat cabang' : 'Dari kurir';
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
