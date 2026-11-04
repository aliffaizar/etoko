<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property int|null $branch_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $avatar_path
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch|null $branch
 */
#[Fillable(['branch_id', 'name', 'email', 'phone', 'avatar_path', 'is_active', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The user's single application role, or null when none is assigned.
     */
    public function appRole(): ?UserRole
    {
        $name = $this->getRoleNames()->first();

        return $name === null ? null : UserRole::tryFrom($name);
    }

    public function hasAppRole(UserRole $role): bool
    {
        return $this->appRole() === $role;
    }

    /**
     * Whether the user sees data from every branch (BR-4).
     */
    public function isCentral(): bool
    {
        return $this->appRole()?->isCentral() ?? false;
    }

    /**
     * Whether the user's data access is limited to one branch (BR-3).
     */
    public function isBranchBound(): bool
    {
        return $this->appRole()?->requiresBranch() ?? false;
    }

    public function isCustomer(): bool
    {
        return $this->hasAppRole(UserRole::Customer);
    }

    /**
     * Whether the user may sign in to a management panel.
     */
    public function isStaff(): bool
    {
        $role = $this->appRole();

        return $role !== null && in_array($role, UserRole::staffRoles(), strict: true);
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
    protected function ofRole(Builder $query, UserRole $role): Builder
    {
        return $query->whereHas('roles', fn (Builder $roles) => $roles->where('name', $role->value));
    }

    /**
     * Staff accounts that could be moved into the given branch (P1-E2-T5).
     *
     * Only roles that are bound to a branch qualify, because moving a central
     * account into a branch would mean changing what the account is. Accounts
     * already in the branch are left out, and so is every Super Admin, who is
     * never branch-bound.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function assignableToBranch(Builder $query, Branch $branch): Builder
    {
        $branchRoles = array_map(
            fn (UserRole $role): string => $role->value,
            array_filter(UserRole::staffRoles(), fn (UserRole $role): bool => $role->requiresBranch()),
        );

        return $query
            ->whereHas('roles', fn (Builder $roles) => $roles->whereIn('name', $branchRoles))
            ->where(fn (Builder $outside) => $outside
                ->whereNull('branch_id')
                ->orWhere('branch_id', '!=', $branch->getKey()));
    }
}
