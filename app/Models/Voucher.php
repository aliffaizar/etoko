<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\VoucherAppliesTo;
use App\Enums\VoucherChannel;
use App\Enums\VoucherState;
use App\Enums\VoucherType;
use App\Support\Money;
use Database\Factories\VoucherFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A discount code (P2-E5-T1).
 *
 * Company-wide and managed centrally (`vouchers.manage`, central actors only):
 * no `branch_id` and no branch scope. Where it may be used is a rule —
 * `branches` empty means every branch — not an owner.
 *
 * Whether a particular basket may use it is **not** decided here: that is
 * `VoucherValidator`, which reads these columns. What lives here is what the
 * voucher *is* and the one definition of its state on the clock
 * (`state()` / `scopeInState()`), so the listing badge, its filter and the
 * validator cannot disagree about whether it is running.
 *
 * `Auditable`, because a voucher is money the shop gives away (BR-9).
 * `used_count` is left out of the audit trail: it moves on every checkout and
 * `voucher_usages` already records each one.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property VoucherType $type
 * @property int $value
 * @property int|null $max_discount
 * @property int $min_order_amount
 * @property int|null $quota
 * @property int $used_count
 * @property int $per_user_limit
 * @property VoucherAppliesTo $applies_to
 * @property VoucherChannel $channel
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $is_active
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Branch> $branches
 * @property-read Collection<int, Category> $categories
 * @property-read Collection<int, Product> $products
 * @property-read Collection<int, VoucherUsage> $usages
 * @property-read User|null $creator
 */
#[Fillable([
    'code', 'name', 'description', 'type', 'value', 'max_discount',
    'min_order_amount', 'quota', 'used_count', 'per_user_limit', 'applies_to',
    'channel', 'starts_at', 'ends_at', 'is_active', 'created_by',
])]
class Voucher extends Model
{
    /** @use HasFactory<VoucherFactory> */
    use Auditable, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => VoucherType::class,
            'applies_to' => VoucherAppliesTo::class,
            'channel' => VoucherChannel::class,
            'value' => 'integer',
            'max_discount' => 'integer',
            'min_order_amount' => 'integer',
            'quota' => 'integer',
            'used_count' => 'integer',
            'per_user_limit' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Codes are stored upper-case and trimmed, so what a customer types finds
     * the voucher however they capitalised it. `findByCode()` normalises the
     * same way.
     *
     * @return Attribute<string, string>
     */
    protected function code(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => self::normalizeCode($value));
    }

    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    public static function findByCode(string $code): ?self
    {
        $normalized = self::normalizeCode($code);

        if ($normalized === '') {
            return null;
        }

        return static::query()->where('code', $normalized)->first();
    }

    /**
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'voucher_branches');
    }

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'voucher_categories');
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'voucher_products');
    }

    /**
     * @return HasMany<VoucherUsage, $this>
     */
    public function usages(): HasMany
    {
        return $this->hasMany(VoucherUsage::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Where the voucher stands right now.
     *
     * Checked in the order an admin would want it answered: switched off
     * beats everything, a voucher that has not started is "scheduled" even
     * when its quota is full, and a used-up quota is reported apart from a
     * passed end date because the fix is different (raise the quota versus
     * extend the period).
     */
    public function state(?Carbon $now = null): VoucherState
    {
        $now ??= now();

        return match (true) {
            ! $this->is_active => VoucherState::Inactive,
            $this->starts_at !== null && $this->starts_at->greaterThan($now) => VoucherState::Scheduled,
            $this->ends_at !== null && $this->ends_at->lessThan($now) => VoucherState::Ended,
            $this->isQuotaExhausted() => VoucherState::Exhausted,
            default => VoucherState::Running,
        };
    }

    public function isQuotaExhausted(): bool
    {
        return $this->quota !== null && $this->used_count >= $this->quota;
    }

    /**
     * How much quota is left, or null when there is no quota.
     */
    public function remainingQuota(): ?int
    {
        return $this->quota === null ? null : max($this->quota - $this->used_count, 0);
    }

    /**
     * The same rule as `state()`, as a query for the listing's filter.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function inState(Builder $query, VoucherState $state): Builder
    {
        $now = now();

        $started = fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
        $notEnded = fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
        $quotaLeft = fn (Builder $q) => $q->whereNull('quota')->orWhereColumn('used_count', '<', 'quota');

        return match ($state) {
            VoucherState::Inactive => $query->where('is_active', false),
            VoucherState::Scheduled => $query->where('is_active', true)->where('starts_at', '>', $now),
            VoucherState::Ended => $query->where('is_active', true)->where($started)->where('ends_at', '<', $now),
            VoucherState::Exhausted => $query->where('is_active', true)->where($started)->where($notEnded)
                ->whereNotNull('quota')->whereColumn('used_count', '>=', 'quota'),
            VoucherState::Running => $query->where('is_active', true)->where($started)->where($notEnded)->where($quotaLeft),
        };
    }

    /**
     * One line describing what the voucher gives, for the listing, the cart
     * and the admin's preview: "Diskon 10% (maks Rp25.000)".
     */
    public function benefitLabel(): string
    {
        $cap = $this->max_discount !== null ? ' (maks '.Money::format($this->max_discount).')' : '';

        return match ($this->type) {
            VoucherType::Percentage => "Diskon {$this->value}%{$cap}",
            VoucherType::Fixed => 'Potongan '.Money::format($this->value),
            VoucherType::FreeShipping => 'Gratis ongkir'.$cap,
        };
    }

    /**
     * Why this voucher cannot be deleted, or null when it can.
     *
     * A redeemed voucher is part of the history of the orders that used it,
     * so it is switched off instead. One source for `destroy()` and for the
     * listing's `can.delete` flag.
     */
    public function deletionBlockedReason(): ?string
    {
        $usages = $this->usages()->count();

        if ($usages > 0) {
            return "Voucher ini sudah dipakai di {$usages} pesanan. Nonaktifkan saja agar riwayat pesanan tetap utuh.";
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected function auditExclude(): array
    {
        return [...$this->auditExcludeDefaults(), 'used_count'];
    }
}
