<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Voucher\SaveVoucher;
use App\Enums\VoucherAppliesTo;
use App\Enums\VoucherChannel;
use App\Enums\VoucherState;
use App\Enums\VoucherType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexVoucherRequest;
use App\Http\Requests\Admin\StoreVoucherRequest;
use App\Http\Requests\Admin\UpdateVoucherRequest;
use App\Http\Requests\Admin\UpdateVoucherStatusRequest;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use App\Support\CategoryTree;
use App\Support\VoucherPresenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Voucher management (P2-E5-T2).
 *
 * Central only: the route needs `vouchers.manage` and `VoucherPolicy` also
 * demands a central actor (docs/04-rbac.md, Marketing). Vouchers belong to no
 * branch, so there is no scoping here; which branches a voucher works at is
 * one of its fields.
 *
 * A voucher that has been redeemed is switched off rather than deleted
 * (`Voucher::deletionBlockedReason()`), so the orders that used it keep a
 * voucher to point at.
 */
class VoucherController extends Controller
{
    private const PER_PAGE = 20;

    public function index(IndexVoucherRequest $request): Response
    {
        $this->authorize('viewAny', Voucher::class);

        /** @var User $actor */
        $actor = $request->user();
        $filters = $request->filters();

        return Inertia::render('admin/vouchers/index', [
            'vouchers' => $this->paginated($filters, $actor),
            'filters' => $filters,
            'options' => [
                'states' => VoucherState::options(),
                'types' => VoucherType::options(),
            ],
            'can' => ['create' => $actor->can('create', Voucher::class)],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Voucher::class);

        return Inertia::render('admin/vouchers/create', [
            'options' => $this->formOptions(),
        ]);
    }

    public function store(StoreVoucherRequest $request, SaveVoucher $saveVoucher): RedirectResponse
    {
        $this->authorize('create', Voucher::class);

        /** @var User $actor */
        $actor = $request->user();
        $voucher = $saveVoucher->handle($request->voucherData(), actor: $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Voucher {$voucher->code} berhasil dibuat."]);

        return to_route('admin.vouchers.edit', $voucher);
    }

    public function edit(Voucher $voucher): Response
    {
        $this->authorize('update', $voucher);

        $voucher->load(['branches:id', 'categories:id', 'products:id']);

        return Inertia::render('admin/vouchers/edit', [
            'voucher' => VoucherPresenter::detail($voucher),
            'usages' => $voucher->usages()
                ->with(['order:id,order_number,customer_name', 'user:id,name'])
                ->latest('used_at')
                ->limit(10)
                ->get()
                ->map(fn (VoucherUsage $usage): array => VoucherPresenter::usage($usage))
                ->all(),
            'options' => $this->formOptions(),
        ]);
    }

    public function update(UpdateVoucherRequest $request, Voucher $voucher, SaveVoucher $saveVoucher): RedirectResponse
    {
        $this->authorize('update', $voucher);

        $saveVoucher->handle($request->voucherData(), $voucher);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Perubahan voucher berhasil disimpan.']);

        return to_route('admin.vouchers.edit', $voucher);
    }

    public function updateStatus(UpdateVoucherStatusRequest $request, Voucher $voucher): RedirectResponse
    {
        $this->authorize('update', $voucher);

        $isActive = $request->boolean('is_active');
        $voucher->update(['is_active' => $isActive]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $isActive ? "Voucher {$voucher->code} diaktifkan." : "Voucher {$voucher->code} dinonaktifkan.",
        ]);

        return back();
    }

    public function destroy(Voucher $voucher): RedirectResponse
    {
        $this->authorize('delete', $voucher);

        $blocked = $voucher->deletionBlockedReason();

        abort_if($blocked !== null, 403, (string) $blocked);

        $voucher->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Voucher {$voucher->code} dihapus."]);

        return to_route('admin.vouchers.index');
    }

    /**
     * @param  array{search: string, state: string, type: string, sort: string, direction: string}  $filters
     * @return LengthAwarePaginator<int, mixed>
     */
    private function paginated(array $filters, User $actor): LengthAwarePaginator
    {
        return Voucher::query()
            ->when($filters['search'] !== '', fn (Builder $query) => $query->where(
                fn (Builder $match) => $match
                    ->where('code', 'like', '%'.Voucher::normalizeCode($filters['search']).'%')
                    ->orWhere('name', 'like', "%{$filters['search']}%"),
            ))
            ->when($filters['state'] !== '', fn (Builder $query) => $query->inState(VoucherState::from($filters['state'])))
            ->when($filters['type'] !== '', fn (Builder $query) => $query->where('type', $filters['type']))
            ->orderBy($filters['sort'], $filters['direction'] === 'asc' ? 'asc' : 'desc')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Voucher $voucher): array => VoucherPresenter::listRow($voucher, $actor));
    }

    /**
     * Everything the form's selects and pickers offer.
     *
     * Products are offered whole (id, name) rather than searched over HTTP:
     * the picker filters them in the browser, which is enough for a catalogue
     * of this shop's size and needs no endpoint of its own.
     *
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'types' => VoucherType::options(),
            'applies_to' => VoucherAppliesTo::options(),
            'channels' => VoucherChannel::options(),
            'branches' => Branch::withoutGlobalScopes()
                ->ordered()
                ->get(['id', 'name'])
                ->map(fn (Branch $branch): array => ['value' => $branch->id, 'label' => $branch->name])
                ->all(),
            'categories' => array_map(
                fn (array $row): array => [
                    'value' => $row['category']->id,
                    'label' => $row['category']->name,
                    'depth' => $row['depth'],
                ],
                CategoryTree::of(Category::query()->get())->flatten(),
            ),
            'products' => Product::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Product $product): array => ['value' => $product->id, 'label' => $product->name])
                ->all(),
        ];
    }
}
