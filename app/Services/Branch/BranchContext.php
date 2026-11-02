<?php

namespace App\Services\Branch;

use Closure;

/**
 * Holds the branch a request is restricted to.
 *
 * The context starts unrestricted. Only `restrictTo()` narrows it, which the
 * SetBranchContext middleware does for users whose role is bound to one branch.
 * Console commands, queued jobs and central staff therefore see every branch,
 * while branch staff see their own branch only (BR-3, BR-4).
 *
 * This is about *data scoping for staff*. The branch a customer shops from is a
 * separate concern handled by the storefront branch picker.
 */
class BranchContext
{
    private ?int $branchId = null;

    public function restrictTo(int $branchId): void
    {
        $this->branchId = $branchId;
    }

    public function clearRestriction(): void
    {
        $this->branchId = null;
    }

    public function isRestricted(): bool
    {
        return $this->branchId !== null;
    }

    public function branchId(): ?int
    {
        return $this->branchId;
    }

    /**
     * Run a callback with the branch restriction temporarily removed.
     *
     * Use this for central reporting, jobs and commands that must read across
     * branches. Always state why at the call site.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withoutRestriction(Closure $callback): mixed
    {
        $previous = $this->branchId;
        $this->branchId = null;

        try {
            return $callback();
        } finally {
            $this->branchId = $previous;
        }
    }

    /**
     * Run a callback restricted to a specific branch.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function forBranch(int $branchId, Closure $callback): mixed
    {
        $previous = $this->branchId;
        $this->branchId = $branchId;

        try {
            return $callback();
        } finally {
            $this->branchId = $previous;
        }
    }
}
