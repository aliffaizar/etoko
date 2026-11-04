<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Branch\CreateBranch;
use App\Actions\Branch\UpdateBranch;
use App\Enums\BranchStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexBranchRequest;
use App\Http\Requests\Admin\StoreBranchRequest;
use App\Http\Requests\Admin\UpdateBranchRequest;
use App\Http\Requests\Admin\UpdateBranchStatusRequest;
use App\Models\Branch;
use App\Models\BranchOperatingHour;
use App\Models\User;
use App\Models\Warehouse;
use App\Policies\UserPolicy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Branch management (P1-E2-T3).
 *
 * The branch screen is where a branch's whole configuration lives: its details
 * and address (T3), its weekly opening hours (T4), its staff (T5) and its
 * warehouses (T6). The hours, staff and warehouses are submitted to their own
 * controllers; this one renders them and owns the branch record itself.
 *
 * A branch admin reaches these routes too — the matrix in docs/04-rbac.md gives
 * them `branches.view` and `branches.update` for their own branch — so the
 * listing is narrowed to their branch and every single-branch route passes
 * through `BranchPolicy` (BR-3). `Branch` carries no branch global scope,
 * because it is the branch, so that narrowing is written out here.
 */
class BranchController extends Controller
{
    private const PER_PAGE = 15;

    public function index(IndexBranchRequest $request): Response
    {
        $this->authorize('viewAny', Branch::class);

        $filters = $request->filters();

        return Inertia::render('admin/branches/index', [
            'branches' => $this->paginatedBranches($request->actingUser(), $filters),
            'filters' => $filters,
            'options' => ['statuses' => BranchStatus::options()],
            'can' => ['create' => $request->actingUser()->can('create', Branch::class)],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Branch::class);

        return Inertia::render('admin/branches/create', [
            'options' => ['statuses' => BranchStatus::options()],
        ]);
    }

    public function store(StoreBranchRequest $request, CreateBranch $createBranch): RedirectResponse
    {
        $this->authorize('create', Branch::class);

        $branch = $createBranch->handle($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Cabang {$branch->name} berhasil dibuat."]);

        return to_route('admin.branches.edit', $branch);
    }

    public function edit(Branch $branch): Response
    {
        $this->authorize('update', $branch);

        /** @var User $actor */
        $actor = request()->user();

        $branch->load([
            'operatingHours' => fn ($query) => $query->orderBy('day_of_week'),
            'warehouses' => fn ($query) => $query->orderByDesc('is_default')->orderBy('code'),
            'users.roles:id,name',
        ]);

        return Inertia::render('admin/branches/edit', [
            'branch' => $this->branchDetail($branch),
            'operatingHours' => $this->operatingHours($branch),
            'warehouses' => $this->warehouses($branch),
            'staff' => $this->staff($branch, $actor),
            'assignableStaff' => $this->assignableStaff($branch, $actor),
            'options' => [
                'statuses' => BranchStatus::options(),
                'roles' => $this->assignableRoleOptions($actor),
            ],
            'can' => $this->abilities($actor, $branch),
        ]);
    }

    public function update(UpdateBranchRequest $request, Branch $branch, UpdateBranch $updateBranch): RedirectResponse
    {
        $this->authorize('update', $branch);

        $updateBranch->handle($branch, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Perubahan cabang berhasil disimpan.']);

        return to_route('admin.branches.edit', $branch);
    }

    /**
     * The quick status change on the listing. A branch that is not active stops
     * accepting new orders (`BranchStatus::acceptsOrders`).
     */
    public function updateStatus(UpdateBranchStatusRequest $request, Branch $branch): RedirectResponse
    {
        $this->authorize('update', $branch);

        $status = $request->branchStatus();

        $branch->update(['status' => $status]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Status cabang {$branch->name} diubah menjadi {$status->label()}.",
        ]);

        return back();
    }

    /**
     * Soft deletes the branch, keeping its warehouses and history intact.
     *
     * What is refused outright is listed by `Branch::deletionBlockedReason()`,
     * which the listing and the branch screen also read for their `can.delete`
     * flag, so the interface never offers a deletion this method will refuse.
     */
    public function destroy(Branch $branch): RedirectResponse
    {
        $this->authorize('delete', $branch);

        $blocked = $branch->deletionBlockedReason();

        abort_if($blocked !== null, 403, (string) $blocked);

        $branch->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Cabang {$branch->name} dihapus."]);

        return to_route('admin.branches.index');
    }

    /**
     * @param  array{search: string, status: string, fulfillment: string, sort: string, direction: string}  $filters
     * @return LengthAwarePaginator<int, mixed>
     */
    private function paginatedBranches(User $actor, array $filters): LengthAwarePaginator
    {
        return $this->listQuery($actor, $filters)
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Branch $branch): array => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
                'city_name' => $branch->city_name,
                'province_name' => $branch->province_name,
                'phone' => $branch->phone,
                'status' => $branch->status->value,
                'status_label' => $branch->status->label(),
                'supports_delivery' => $branch->supports_delivery,
                'supports_pickup' => $branch->supports_pickup,
                'is_default' => $branch->is_default,
                'sort_order' => $branch->sort_order,
                'warehouse_count' => (int) $branch->getAttribute('warehouses_count'),
                'staff_count' => (int) $branch->getAttribute('users_count'),
                'can' => [
                    'update' => $actor->can('update', $branch),
                    'delete' => $actor->can('delete', $branch) && $branch->isDeletable(),
                ],
                // Why deletion is off, for a tooltip on the disabled action.
                // Null when the actor simply may not delete branches at all.
                'delete_blocked_reason' => $actor->can('delete', $branch)
                    ? $branch->deletionBlockedReason()
                    : null,
            ]);
    }

    /**
     * @param  array{search: string, status: string, fulfillment: string, sort: string, direction: string}  $filters
     * @return Builder<Branch>
     */
    private function listQuery(User $actor, array $filters): Builder
    {
        return Branch::query()
            ->withCount(['warehouses', 'users'])
            // BR-3: a branch-bound actor never sees another branch.
            ->when(! $actor->isCentral(), fn (Builder $query) => $query->whereKey($actor->branch_id))
            ->when($filters['search'] !== '', fn (Builder $query) => $query->where(
                fn (Builder $match) => $match
                    ->where('name', 'like', "%{$filters['search']}%")
                    ->orWhere('code', 'like', "%{$filters['search']}%")
                    ->orWhere('city_name', 'like', "%{$filters['search']}%"),
            ))
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['fulfillment'] === 'delivery', fn (Builder $query) => $query->where('supports_delivery', true))
            ->when($filters['fulfillment'] === 'pickup', fn (Builder $query) => $query->where('supports_pickup', true))
            ->orderBy($filters['sort'], $filters['direction'] === 'desc' ? 'desc' : 'asc')
            ->orderBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function branchDetail(Branch $branch): array
    {
        return [
            'id' => $branch->id,
            'code' => $branch->code,
            'name' => $branch->name,
            'slug' => $branch->slug,
            'description' => $branch->description,
            'phone' => $branch->phone,
            'email' => $branch->email,
            'province_name' => $branch->province_name,
            'city_name' => $branch->city_name,
            'district_name' => $branch->district_name,
            'postal_code' => $branch->postal_code,
            'address_line' => $branch->address_line,
            'latitude' => $branch->latitude,
            'longitude' => $branch->longitude,
            'status' => $branch->status->value,
            'status_label' => $branch->status->label(),
            'supports_delivery' => $branch->supports_delivery,
            'supports_pickup' => $branch->supports_pickup,
            'is_default' => $branch->is_default,
            'sort_order' => $branch->sort_order,
            'created_at' => $branch->created_at?->toIso8601String(),
        ];
    }

    /**
     * All seven days, in order.
     *
     * A day the branch has no row for is presented as closed rather than left
     * out, so the form always submits a complete week even for a branch whose
     * rows predate P1-E2-T4.
     *
     * @return array<int, array{day_of_week: int, day_label: string, opens_at: string|null, closes_at: string|null, is_closed: bool}>
     */
    private function operatingHours(Branch $branch): array
    {
        $stored = $branch->operatingHours->keyBy('day_of_week');

        return array_map(function (int $day) use ($stored): array {
            $hour = $stored->get($day);

            return [
                'day_of_week' => $day,
                'day_label' => BranchOperatingHour::DAY_LABELS[$day],
                'opens_at' => $hour instanceof BranchOperatingHour ? $hour->opensAtTime() : null,
                'closes_at' => $hour instanceof BranchOperatingHour ? $hour->closesAtTime() : null,
                'is_closed' => $hour instanceof BranchOperatingHour ? $hour->is_closed : true,
            ];
        }, range(0, 6));
    }

    /**
     * @return array<int, array{id: int, code: string, name: string, address: string|null, is_default: bool, is_active: bool}>
     */
    private function warehouses(Branch $branch): array
    {
        return $branch->warehouses
            ->map(fn (Warehouse $warehouse): array => [
                'id' => $warehouse->id,
                'code' => $warehouse->code,
                'name' => $warehouse->name,
                'address' => $warehouse->address,
                'is_default' => $warehouse->is_default,
                'is_active' => $warehouse->is_active,
            ])
            ->all();
    }

    /**
     * The accounts stationed at this branch (P1-E2-T5).
     *
     * @return array<int, array<string, mixed>>
     */
    private function staff(Branch $branch, User $actor): array
    {
        return $branch->users
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->appRole()?->value,
                'role_label' => $user->appRole()?->label(),
                'is_active' => $user->is_active,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'can_update' => $actor->can('update', $user),
            ])
            ->values()
            ->all();
    }

    /**
     * Staff of other branches that may be moved here (P1-E2-T5).
     *
     * @return array<int, array{value: int, label: string}>
     */
    private function assignableStaff(Branch $branch, User $actor): array
    {
        if (! $actor->can('assignStaff', $branch)) {
            return [];
        }

        return User::query()
            ->assignableToBranch($branch)
            ->whereKeyNot($actor->getKey())
            ->with(['branch:id,code', 'roles:id,name'])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'branch_id'])
            ->map(fn (User $user): array => [
                'value' => $user->id,
                'label' => $user->branch === null
                    ? "{$user->name} ({$user->email})"
                    : "{$user->name} — {$user->branch->code}",
            ])
            ->all();
    }

    /**
     * Branch-bound roles the actor may grant, for the staff assignment dialog.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function assignableRoleOptions(User $actor): array
    {
        $grantable = app(UserPolicy::class)->grantableRoles($actor);

        return array_values(array_map(
            fn (string $role): array => ['value' => $role, 'label' => UserRole::from($role)->label()],
            array_filter($grantable, fn (string $role): bool => UserRole::from($role)->requiresBranch()),
        ));
    }

    /**
     * @return array{update: bool, delete: bool, delete_blocked_reason: string|null, manage_warehouses: bool, assign_staff: bool, create_staff: bool}
     */
    private function abilities(User $actor, Branch $branch): array
    {
        return [
            'update' => $actor->can('update', $branch),
            'delete' => $actor->can('delete', $branch) && $branch->isDeletable(),
            'delete_blocked_reason' => $actor->can('delete', $branch)
                ? $branch->deletionBlockedReason()
                : null,
            'manage_warehouses' => $actor->can('manageWarehouses', $branch),
            'assign_staff' => $actor->can('assignStaff', $branch),
            'create_staff' => $actor->can('create', User::class),
        ];
    }
}
