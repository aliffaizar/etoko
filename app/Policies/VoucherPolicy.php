<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Voucher;

/**
 * Who may manage vouchers (P2-E5-T2).
 *
 * Marketing is central (docs/04-rbac.md): every ability demands
 * `vouchers.manage` **and** a central actor, so granting the permission to a
 * branch role on the Role screen does not let a branch hand out the company's
 * money. A Super Admin passes through `Gate::before`.
 */
class VoucherPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manages($user);
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, Voucher $voucher): bool
    {
        return $this->manages($user);
    }

    public function delete(User $user, Voucher $voucher): bool
    {
        return $this->manages($user);
    }

    private function manages(User $user): bool
    {
        return $user->can('vouchers.manage') && $user->isCentral();
    }
}
