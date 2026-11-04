<?php

namespace App\Http\Controllers\Admin;

use App\Actions\User\CreateUser;
use App\Actions\User\SetUserPassword;
use App\Actions\User\UpdateUser;
use App\Enums\BranchStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexUserRequest;
use App\Http\Requests\Admin\ResetUserPasswordRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff account management (P1-E1-T4).
 *
 * The screen lists the five panel roles only; shoppers are a separate domain
 * with their own permissions and their own screen (`customers.*`, P1-E6-T7).
 *
 * A branch admin reaches these routes too — the matrix in docs/04-rbac.md gives
 * them `users.view/create/update` for their own staff — so every query is
 * narrowed to their branch and every single-account route additionally passes
 * through `UserPolicy`.
 */
class UserController extends Controller
{
    private const PER_PAGE = 15;

    public function index(IndexUserRequest $request): Response
    {
        $this->authorize('viewAny', User::class);

        $filters = $request->filters();

        return Inertia::render('admin/users/index', [
            'users' => $this->paginatedUsers($request->actingUser(), $filters),
            'filters' => $filters,
            'options' => $this->filterOptions($request->actingUser()),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', User::class);

        $options = $this->formOptions();

        return Inertia::render('admin/users/create', [
            'options' => $options,
            // The branch screen links here to staff a specific branch
            // (P1-E2-T5); an id the actor may not assign is ignored.
            'prefill' => ['branch_id' => $this->prefilledBranchId($request, $options['branches'])],
        ]);
    }

    /**
     * @param  array<int, array{value: int, label: string}>  $branches
     */
    private function prefilledBranchId(Request $request, array $branches): ?int
    {
        $branchId = $request->integer('branch_id');

        return in_array($branchId, array_column($branches, 'value'), strict: true) ? $branchId : null;
    }

    public function store(StoreUserRequest $request, CreateUser $createUser): RedirectResponse
    {
        $this->authorize('create', User::class);

        /** @var array{name: string, email: string, role: string, password: string, phone?: string|null, branch_id?: int|null, is_active?: bool} $attributes */
        $attributes = $request->validated();

        $user = $createUser->handle($attributes);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Pengguna {$user->name} berhasil dibuat."]);

        return to_route('admin.users.index');
    }

    public function edit(User $user): Response
    {
        $this->authorize('update', $user);

        $user->loadMissing(['branch:id,name,code', 'roles:id,name']);

        return Inertia::render('admin/users/edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'is_active' => $user->is_active,
                'branch_id' => $user->branch_id,
                'role' => $user->appRole()?->value,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'options' => $this->formOptions(),
            'can' => [
                'delete' => request()->user()?->can('delete', $user) ?? false,
            ],
        ]);
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUser $updateUser): RedirectResponse
    {
        $this->authorize('update', $user);

        /** @var array{name: string, email: string, role: string, phone?: string|null, branch_id?: int|null, is_active?: bool} $attributes */
        $attributes = $request->validated();

        $updateUser->handle($user, $attributes);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Perubahan pengguna berhasil disimpan.']);

        return to_route('admin.users.edit', $user);
    }

    /**
     * Activate or deactivate an account. A deactivated account can no longer
     * sign in (P1-E1-T3), which is why nobody may deactivate their own.
     */
    public function updateStatus(UpdateUserStatusRequest $request, User $user): RedirectResponse
    {
        $this->authorize('toggleActive', $user);
        $this->denySelf($request->user(), $user, 'Anda tidak dapat mengubah status akun Anda sendiri.');

        $isActive = $request->boolean('is_active');

        $user->update(['is_active' => $isActive]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $isActive
                ? "Pengguna {$user->name} diaktifkan."
                : "Pengguna {$user->name} dinonaktifkan.",
        ]);

        return back();
    }

    public function updatePassword(ResetUserPasswordRequest $request, User $user, SetUserPassword $setUserPassword): RedirectResponse
    {
        $this->authorize('resetPassword', $user);

        $setUserPassword->handle($user, (string) $request->validated('password'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Kata sandi berhasil diatur ulang.']);

        return back();
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);
        $this->denySelf(request()->user(), $user, 'Anda tidak dapat menghapus akun Anda sendiri.');

        $user->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Pengguna {$user->name} dihapus."]);

        return to_route('admin.users.index');
    }

    /**
     * A Super Admin bypasses every policy through `Gate::before`, so the guard
     * against acting on one's own account has to live outside the policy.
     */
    private function denySelf(?User $actor, User $target, string $message): void
    {
        abort_if($actor !== null && $actor->is($target), 403, $message);
    }

    /**
     * @param  array{search: string, role: string, branch: string, status: string, sort: string, direction: string}  $filters
     * @return LengthAwarePaginator<int, mixed>
     */
    private function paginatedUsers(User $actor, array $filters): LengthAwarePaginator
    {
        return $this->listQuery($actor, $filters)
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'is_active' => $user->is_active,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'role' => $user->appRole()?->value,
                'role_label' => $user->appRole()?->label(),
                'branch' => $user->branch === null ? null : [
                    'id' => $user->branch->id,
                    'name' => $user->branch->name,
                    'code' => $user->branch->code,
                ],
                'is_self' => $actor->is($user),
                'can' => [
                    'update' => $actor->can('update', $user),
                    'delete' => $actor->can('delete', $user) && $actor->isNot($user),
                    'toggle_active' => $actor->can('toggleActive', $user) && $actor->isNot($user),
                ],
            ]);
    }

    /**
     * @param  array{search: string, role: string, branch: string, status: string, sort: string, direction: string}  $filters
     * @return Builder<User>
     */
    private function listQuery(User $actor, array $filters): Builder
    {
        return User::query()
            ->with(['branch:id,name,code', 'roles:id,name'])
            ->whereHas('roles', fn (Builder $roles) => $roles->whereIn('name', UserRole::staffRoleValues()))
            // BR-3: a branch-bound actor never sees staff of another branch.
            ->when(! $actor->isCentral(), fn (Builder $query) => $query->where('branch_id', $actor->branch_id))
            ->when($filters['search'] !== '', fn (Builder $query) => $query->where(
                fn (Builder $match) => $match
                    ->where('name', 'like', "%{$filters['search']}%")
                    ->orWhere('email', 'like', "%{$filters['search']}%")
                    ->orWhere('phone', 'like', "%{$filters['search']}%"),
            ))
            ->when($filters['role'] !== '', fn (Builder $query) => $query->whereHas(
                'roles',
                fn (Builder $roles) => $roles->where('name', $filters['role']),
            ))
            ->when($filters['branch'] === IndexUserRequest::CENTRAL_BRANCH, fn (Builder $query) => $query->whereNull('branch_id'))
            ->when(
                $filters['branch'] !== '' && $filters['branch'] !== IndexUserRequest::CENTRAL_BRANCH,
                fn (Builder $query) => $query->where('branch_id', (int) $filters['branch']),
            )
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('is_active', $filters['status'] === 'active'))
            ->orderBy($filters['sort'], $filters['direction'] === 'desc' ? 'desc' : 'asc')
            ->orderBy('id');
    }

    /**
     * Options for the listing's filter bar.
     *
     * @return array{roles: array<int, array{value: string, label: string}>, branches: array<int, array{value: string, label: string}>, showBranchFilter: bool}
     */
    private function filterOptions(User $actor): array
    {
        return [
            'roles' => UserRole::optionsFor(UserRole::staffRoles()),
            'branches' => $actor->isCentral() ? $this->branchFilterOptions() : [],
            'showBranchFilter' => $actor->isCentral(),
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function branchFilterOptions(): array
    {
        $branches = Branch::query()
            ->ordered()
            ->get(['id', 'name', 'code'])
            ->map(fn (Branch $branch): array => [
                'value' => (string) $branch->id,
                'label' => "{$branch->name} ({$branch->code})",
            ])
            ->all();

        return [
            ['value' => IndexUserRequest::CENTRAL_BRANCH, 'label' => 'Pusat (tanpa cabang)'],
            ...$branches,
        ];
    }

    /**
     * Options for the create and edit forms, bounded by what the actor may grant.
     *
     * @return array{roles: array<int, array{value: string, label: string, requires_branch: bool}>, branches: array<int, array{value: int, label: string}>, lockedBranchId: int|null}
     */
    private function formOptions(): array
    {
        /** @var User $actor */
        $actor = request()->user();

        $grantable = $actor->appRole()?->grantableRoles() ?? [];

        return [
            'roles' => array_values(array_map(fn (UserRole $role): array => [
                'value' => $role->value,
                'label' => $role->label(),
                'requires_branch' => $role->requiresBranch(),
            ], $grantable)),
            'branches' => Branch::query()
                ->where('status', BranchStatus::Active)
                ->when(! $actor->isCentral(), fn (Builder $query) => $query->whereKey($actor->branch_id))
                ->ordered()
                ->get(['id', 'name', 'code'])
                ->map(fn (Branch $branch): array => [
                    'value' => $branch->id,
                    'label' => "{$branch->name} ({$branch->code})",
                ])
                ->all(),
            // A branch admin may only ever assign their own branch, so the form
            // preselects it and shows it as read-only.
            'lockedBranchId' => $actor->isCentral() ? null : $actor->branch_id,
        ];
    }
}
