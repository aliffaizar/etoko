<?php

namespace App\Concerns;

use App\Enums\BranchStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * Shared validation for the role and branch of a staff account.
 *
 * Both values are bounded by who is filling the form: an actor may only grant a
 * role they could hold themselves, and a branch-bound actor may only ever point
 * an account at their own branch (docs/04-rbac.md). Validating it here means
 * the rule is enforced on the request rather than trusted from the UI, which
 * only hides the options it should not offer.
 *
 * @phpstan-require-extends FormRequest
 */
trait ValidatesStaffAssignment
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function staffAssignmentRules(): array
    {
        return [
            'role' => ['required', 'string', $this->grantableRoleRule()],
            'branch_id' => [
                Rule::requiredIf(fn (): bool => $this->selectedRole()?->requiresBranch() === true),
                'nullable',
                'integer',
                $this->assignableBranchRule(),
            ],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function staffAssignmentAttributes(): array
    {
        return [
            'role' => 'role',
            'branch_id' => 'cabang',
            'is_active' => 'status aktif',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function staffAssignmentMessages(): array
    {
        return [
            'role.in' => 'Anda tidak berhak memberikan role tersebut.',
            'branch_id.required' => 'Role ini wajib ditugaskan ke satu cabang.',
            // Central staff have no branch of their own, so the reason they can
            // be refused is a different one: the branch is not open.
            'branch_id.in' => $this->actor()->isCentral()
                ? 'Cabang tersebut tidak dapat dipilih. Pilih cabang yang aktif.'
                : 'Anda hanya dapat menugaskan pengguna ke cabang Anda sendiri.',
        ];
    }

    protected function actor(): User
    {
        /** @var User $user */
        $user = $this->user();

        return $user;
    }

    protected function selectedRole(): ?UserRole
    {
        return UserRole::tryFrom((string) $this->input('role'));
    }

    private function grantableRoleRule(): In
    {
        return Rule::in(app(UserPolicy::class)->grantableRoles($this->actor()));
    }

    /**
     * Central staff may point an account at any active branch; a branch-bound
     * actor only at their own.
     *
     * The branch an account already sits in is always allowed on top of that,
     * whatever its status. Otherwise a branch going inactive or into
     * maintenance would freeze every field of its staff — the edit form always
     * resubmits the account's own `branch_id` — right when deactivating those
     * accounts matters most. "Only active branches" stays the limit for
     * creating an account and for moving one to a different branch.
     */
    private function assignableBranchRule(): In
    {
        $actor = $this->actor();

        if (! $actor->isCentral()) {
            return Rule::in(array_filter([$actor->branch_id]));
        }

        /** @var array<int, int> $activeBranchIds */
        $activeBranchIds = Branch::query()->where('status', BranchStatus::Active)->pluck('id')->all();

        $currentBranchId = $this->currentBranchId();

        return Rule::in($currentBranchId === null
            ? $activeBranchIds
            : array_values(array_unique([...$activeBranchIds, $currentBranchId])));
    }

    /**
     * The branch the account being edited is stationed at, if any.
     *
     * Null on the create form, which has no account yet.
     */
    protected function currentBranchId(): ?int
    {
        $user = $this->route('user');

        return $user instanceof User ? $user->branch_id : null;
    }
}
