<?php

namespace App\Policies;

use App\Models\Promotion;
use App\Models\User;

/**
 * Who may manage promotions (P2-E8-T2).
 *
 * Marketing is central (docs/04-rbac.md): every ability demands
 * `promotions.manage` **and** a central actor, the same rule as `VoucherPolicy`.
 * A Super Admin passes through `Gate::before`.
 */
class PromotionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manages($user);
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, Promotion $promotion): bool
    {
        return $this->manages($user);
    }

    private function manages(User $user): bool
    {
        return $user->can('promotions.manage') && $user->isCentral();
    }
}
