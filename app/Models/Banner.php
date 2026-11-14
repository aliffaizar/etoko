<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\BannerPosition;
use Database\Factories\BannerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A promotional slide on the storefront (P2-E8-T1).
 *
 * @property int $id
 * @property string $title
 * @property string|null $subtitle
 * @property string|null $image_path
 * @property string|null $mobile_image_path
 * @property string|null $link_url
 * @property BannerPosition $position
 * @property int|null $branch_id
 * @property int $sort_order
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $is_active
 * @property-read Branch|null $branch
 */
#[Fillable([
    'title', 'subtitle', 'image_path', 'mobile_image_path', 'link_url', 'position',
    'branch_id', 'sort_order', 'starts_at', 'ends_at', 'is_active',
])]
class Banner extends Model
{
    /** @use HasFactory<BannerFactory> */
    use Auditable, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => BannerPosition::class,
            'sort_order' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Switched on and inside its period at `$moment`.
     *
     * @param  Builder<Banner>  $query
     * @return Builder<Banner>
     */
    #[Scope]
    protected function live(Builder $query, ?Carbon $moment = null): Builder
    {
        $moment ??= now();

        return $query->where('is_active', true)
            ->where(fn (Builder $started) => $started->whereNull('starts_at')->orWhere('starts_at', '<=', $moment))
            ->where(fn (Builder $notEnded) => $notEnded->whereNull('ends_at')->orWhere('ends_at', '>=', $moment));
    }

    /**
     * Shown at every branch, or at the one named. With no branch (the
     * storefront has not chosen one) only the every-branch banners show.
     *
     * @param  Builder<Banner>  $query
     * @return Builder<Banner>
     */
    #[Scope]
    protected function forBranch(Builder $query, ?Branch $branch): Builder
    {
        return $query->where(function (Builder $scope) use ($branch): void {
            $scope->whereNull('branch_id');

            if ($branch instanceof Branch) {
                $scope->orWhere('branch_id', $branch->getKey());
            }
        });
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The column holds a path on the `public` disk, the way
     * `categories.image_path` does, so the URL is never stored.
     */
    public function imageUrl(): ?string
    {
        return $this->image_path === null ? null : Storage::disk('public')->url($this->image_path);
    }

    public function mobileImageUrl(): ?string
    {
        return $this->mobile_image_path === null ? null : Storage::disk('public')->url($this->mobile_image_path);
    }
}
