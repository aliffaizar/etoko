<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Banner\SaveBanner;
use App\Enums\BannerPosition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBannerRequest;
use App\Http\Requests\Admin\UpdateBannerRequest;
use App\Http\Requests\Admin\UpdateBannerStatusRequest;
use App\Models\Banner;
use App\Models\Branch;
use App\Models\User;
use App\Support\BannerPresenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Homepage banners (P2-E8-T1).
 *
 * Central only: the route needs `banners.manage` and `BannerPolicy` also
 * demands a central actor. A banner is a row, not a file, so deleting one
 * removes its pictures with it.
 */
class BannerController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Banner::class);

        /** @var User $actor */
        $actor = $request->user();

        return Inertia::render('admin/banners/index', [
            'banners' => $this->paginated(),
            'can' => ['create' => $actor->can('create', Banner::class)],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Banner::class);

        return Inertia::render('admin/banners/create', [
            'options' => $this->formOptions(),
        ]);
    }

    public function store(StoreBannerRequest $request, SaveBanner $saveBanner): RedirectResponse
    {
        $this->authorize('create', Banner::class);

        $banner = $saveBanner->handle(
            $request->bannerAttributes(),
            image: $request->file('image'),
            mobileImage: $request->file('mobile_image'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Banner \"{$banner->title}\" berhasil dibuat."]);

        return to_route('admin.banners.edit', $banner);
    }

    public function edit(Banner $banner): Response
    {
        $this->authorize('update', $banner);

        $banner->load('branch:id,name');

        return Inertia::render('admin/banners/edit', [
            'banner' => BannerPresenter::detail($banner),
            'options' => $this->formOptions(),
        ]);
    }

    public function update(UpdateBannerRequest $request, Banner $banner, SaveBanner $saveBanner): RedirectResponse
    {
        $this->authorize('update', $banner);

        $saveBanner->handle(
            $request->bannerAttributes(),
            $banner,
            image: $request->file('image'),
            mobileImage: $request->file('mobile_image'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Perubahan banner berhasil disimpan.']);

        return to_route('admin.banners.edit', $banner);
    }

    public function updateStatus(UpdateBannerStatusRequest $request, Banner $banner): RedirectResponse
    {
        $this->authorize('update', $banner);

        $isActive = $request->boolean('is_active');
        $banner->update(['is_active' => $isActive]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $isActive ? 'Banner ditampilkan.' : 'Banner disembunyikan.',
        ]);

        return back();
    }

    public function destroy(Banner $banner): RedirectResponse
    {
        $this->authorize('delete', $banner);

        $paths = array_filter([$banner->image_path, $banner->mobile_image_path]);
        $banner->delete();

        foreach ($paths as $path) {
            Storage::disk('public')->delete($path);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Banner \"{$banner->title}\" dihapus."]);

        return to_route('admin.banners.index');
    }

    /**
     * @return LengthAwarePaginator<int, mixed>
     */
    private function paginated(): LengthAwarePaginator
    {
        $now = Carbon::now();

        return Banner::query()
            ->with('branch:id,name')
            ->orderBy('position')
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Banner $banner): array => BannerPresenter::listRow($banner, $now));
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'positions' => BannerPosition::options(),
            'branches' => Branch::withoutGlobalScopes()
                ->ordered()
                ->get(['id', 'name'])
                ->map(fn (Branch $branch): array => ['value' => $branch->id, 'label' => $branch->name])
                ->all(),
        ];
    }
}
