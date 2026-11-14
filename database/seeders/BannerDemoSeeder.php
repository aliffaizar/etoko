<?php

namespace Database\Seeders;

use App\Actions\Banner\SaveBanner;
use App\Enums\BannerPosition;
use App\Models\Banner;
use Illuminate\Database\Seeder;

/**
 * The three front-page banners the storefront showed while they were still
 * hard-coded in `HomeController` (P1-E5), now rows an admin can edit
 * (P2-E8-T1).
 *
 * Saved through `SaveBanner`, the action the admin form uses, without
 * pictures — the storefront draws a banner with no picture as a plain card.
 * Skipped per title, so re-running never duplicates or overwrites an edited
 * banner. Never run it against a production database.
 */
class BannerDemoSeeder extends Seeder
{
    public function run(SaveBanner $saveBanner): void
    {
        $catalogue = route('shop.catalog.index');

        $banners = [
            [
                'title' => 'Satu katalog, satu harga',
                'subtitle' => 'Semua produk tersedia di seluruh cabang dengan harga yang sama.',
            ],
            [
                'title' => 'Ambil sendiri di cabang',
                'subtitle' => 'Pesan online, ambil hari yang sama di cabang yang mendukung pickup.',
            ],
            [
                'title' => 'Stok nyata, bukan perkiraan',
                'subtitle' => 'Pilih cabang untuk melihat apa yang bisa kamu bawa pulang hari ini.',
            ],
        ];

        foreach ($banners as $position => $banner) {
            if (Banner::query()->where('title', $banner['title'])->exists()) {
                continue;
            }

            $saveBanner->handle([
                ...$banner,
                'link_url' => $catalogue,
                'position' => BannerPosition::Hero,
                'branch_id' => null,
                'sort_order' => $position + 1,
                'starts_at' => null,
                'ends_at' => null,
                'is_active' => true,
            ]);
        }
    }
}
