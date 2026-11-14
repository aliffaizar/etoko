<?php

namespace App\Policies;

use App\Models\Banner;
use App\Models\User;

/**
 * Who may manage banners (P2-E8-T1).
 *
 * Marketing is central (docs/04-rbac.md): every ability demands
 * `banners.manage` **and** a central actor. A Super Admin passes through
 * `Gate::before`.
 */
class BannerPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manages($user);
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, Banner $banner): bool
    {
        return $this->manages($user);
    }

    public function delete(User $user, Banner $banner): bool
    {
        return $this->manages($user);
    }

    private function manages(User $user): bool
    {
        return $user->can('banners.manage') && $user->isCentral();
    }
}
