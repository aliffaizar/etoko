<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Product\RefreshLowestPrice;
use App\Actions\Promotion\SavePromotion;
use App\Enums\PromotionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePromotionRequest;
use App\Http\Requests\Admin\UpdatePromotionRequest;
use App\Http\Requests\Admin\UpdatePromotionStatusRequest;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use App\Support\PromotionPresenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Product promotions (P2-E8-T2, ADR-067).
 *
 * Central only: the route needs `promotions.manage` and `PromotionPolicy` also
 * demands a central actor. A promotion is not deleted once it has run; it is
 * switched off, so the price history of the orders it priced stays explicable
 * by a row that still exists.
 */
class PromotionController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Promotion::class);

        /** @var User $actor */
        $actor = $request->user();

        return Inertia::render('admin/promotions/index', [
            'promotions' => $this->paginated(),
            'can' => ['create' => $actor->can('create', Promotion::class)],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Promotion::class);

        return Inertia::render('admin/promotions/create', [
            'options' => $this->formOptions(),
        ]);
    }

    public function store(StorePromotionRequest $request, SavePromotion $savePromotion): RedirectResponse
    {
        $this->authorize('create', Promotion::class);

        /** @var User $actor */
        $actor = $request->user();
        $promotion = $savePromotion->handle($request->promotionData(), actor: $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Promo {$promotion->name} berhasil dibuat."]);

        return to_route('admin.promotions.edit', $promotion);
    }

    public function edit(Promotion $promotion): Response
    {
        $this->authorize('update', $promotion);

        $promotion->load(['products:id,name', 'branches:id,name']);

        return Inertia::render('admin/promotions/edit', [
            'promotion' => PromotionPresenter::detail($promotion),
            'options' => $this->formOptions(),
        ]);
    }

    public function update(UpdatePromotionRequest $request, Promotion $promotion, SavePromotion $savePromotion): RedirectResponse
    {
        $this->authorize('update', $promotion);

        $savePromotion->handle($request->promotionData(), $promotion);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Perubahan promo berhasil disimpan.']);

        return to_route('admin.promotions.edit', $promotion);
    }

    /**
     * Switching a promotion on or off changes the price of every product it
     * names, so those products are refreshed in the same request (ADR-066).
     */
    public function updateStatus(
        UpdatePromotionStatusRequest $request,
        Promotion $promotion,
        RefreshLowestPrice $refreshLowestPrice,
    ): RedirectResponse {
        $this->authorize('update', $promotion);

        $isActive = $request->boolean('is_active');
        $promotion->update(['is_active' => $isActive]);
        $refreshLowestPrice->handle($promotion->products()->pluck('products.id')->all());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $isActive ? "Promo {$promotion->name} diaktifkan." : "Promo {$promotion->name} dinonaktifkan.",
        ]);

        return back();
    }

    /**
     * @return LengthAwarePaginator<int, mixed>
     */
    private function paginated(): LengthAwarePaginator
    {
        $now = Carbon::now();

        return Promotion::query()
            ->withCount('products')
            ->with('branches:id,name')
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Promotion $promotion): array => PromotionPresenter::listRow($promotion, $now));
    }

    /**
     * The products are offered whole, as the voucher form offers them: the
     * picker filters in the browser, which is enough for a catalogue of this
     * size.
     *
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'types' => PromotionType::options(),
            'branches' => Branch::withoutGlobalScopes()
                ->ordered()
                ->get(['id', 'name'])
                ->map(fn (Branch $branch): array => ['value' => $branch->id, 'label' => $branch->name])
                ->all(),
            'products' => Product::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Product $product): array => ['value' => $product->id, 'label' => $product->name])
                ->all(),
        ];
    }
}
