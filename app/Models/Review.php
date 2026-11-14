<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\BelongsToBranch;
use App\Enums\ReviewStatus;
use Database\Factories\ReviewFactory;
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
 * A customer's review of a product they received (P2-E8-T3, ADR-068).
 *
 * @property int $id
 * @property int $product_id
 * @property int $user_id
 * @property int|null $order_item_id
 * @property int|null $branch_id
 * @property int $rating
 * @property string|null $title
 * @property string|null $comment
 * @property bool $is_verified_purchase
 * @property ReviewStatus $status
 * @property string|null $reply
 * @property int|null $replied_by
 * @property Carbon|null $replied_at
 * @property int $helpful_count
 * @property-read Product $product
 * @property-read User $user
 * @property-read OrderItem|null $orderItem
 * @property-read Branch|null $branch
 * @property-read Collection<int, ReviewImage> $images
 */
#[Fillable([
    'product_id', 'user_id', 'order_item_id', 'branch_id', 'rating', 'title', 'comment',
    'is_verified_purchase', 'status', 'reply', 'replied_by', 'replied_at',
])]
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use Auditable, BelongsToBranch, HasFactory;

    /** Where review photos live, on the `public` disk. */
    public const IMAGE_DIRECTORY = 'reviews';

    public const IMAGE_DISK = 'public';

    /** Most photos one review may carry. */
    public const MAX_IMAGES = 5;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'status' => ReviewStatus::class,
            'is_verified_purchase' => 'boolean',
            'replied_at' => 'datetime',
            'helpful_count' => 'integer',
        ];
    }

    /**
     * Only what the storefront may show.
     *
     * @param  Builder<Review>  $query
     * @return Builder<Review>
     */
    #[Scope]
    protected function approved(Builder $query): Builder
    {
        return $query->where('status', ReviewStatus::Approved);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return HasMany<ReviewImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ReviewImage::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function repliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by');
    }

    /**
     * Whether the seller may answer this review now: it must be on show, since
     * an answer to a hidden review would be read by nobody and would leak what
     * the moderator removed.
     */
    public function replyBlockedReason(): ?string
    {
        return $this->status === ReviewStatus::Approved
            ? null
            : 'Balasan hanya bisa ditulis untuk ulasan yang sudah tampil.';
    }
}
