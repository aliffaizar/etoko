<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moves an existing staff account into a branch (P1-E2-T5).
 *
 * Two sets bound what may be submitted: the accounts that could legally move
 * into this branch (`User::assignableToBranch`), narrowed to the ones the actor
 * may edit at all, and the branch-bound roles the actor may grant. Both are
 * validated here rather than trusted from the dialog, which only hides the
 * options it should not offer.
 */
class AssignBranchStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', Rule::in($this->assignableUserIds())],
            'role' => ['required', 'string', Rule::in($this->grantableBranchRoles())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['user_id' => 'pengguna', 'role' => 'role'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.in' => 'Pengguna tersebut tidak dapat ditugaskan ke cabang ini.',
            'role.in' => 'Anda tidak berhak memberikan role tersebut.',
        ];
    }

    public function staffToAssign(): User
    {
        /** @var User $user */
        $user = User::query()->findOrFail((int) $this->validated('user_id'));

        return $user;
    }

    public function assignedRole(): UserRole
    {
        return UserRole::from((string) $this->validated('role'));
    }

    /**
     * Branch-bound roles the actor may grant. Central roles are excluded
     * because an account holding one has no branch to be assigned to.
     *
     * @return array<int, string>
     */
    public function grantableBranchRoles(): array
    {
        $grantable = app(UserPolicy::class)->grantableRoles($this->actor());

        return array_values(array_filter(
            $grantable,
            fn (string $role): bool => UserRole::from($role)->requiresBranch(),
        ));
    }

    /**
     * @return array<int, int>
     */
    private function assignableUserIds(): array
    {
        $actor = $this->actor();

        /** @var array<int, int> $ids */
        $ids = User::query()
            ->assignableToBranch($this->branch())
            ->whereKeyNot($actor->getKey())
            ->pluck('id')
            ->all();

        return $ids;
    }

    private function branch(): Branch
    {
        /** @var Branch $branch */
        $branch = $this->route('branch');

        return $branch;
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = $this->user();

        return $user;
    }
}
