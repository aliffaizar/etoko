<?php

namespace App\Models;

use Database\Factories\ReviewImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One photo attached to a review (P2-E8-T3).
 *
 * @property int $id
 * @property int $review_id
 * @property string $path
 * @property int $sort_order
 */
#[Fillable(['review_id', 'path', 'sort_order'])]
class ReviewImage extends Model
{
    /** @use HasFactory<ReviewImageFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Review, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    /**
     * The column holds a path on the `public` disk, like every other image in
     * the catalogue, so the URL is never stored.
     */
    public function url(): string
    {
        return Storage::disk(Review::IMAGE_DISK)->url($this->path);
    }
}
