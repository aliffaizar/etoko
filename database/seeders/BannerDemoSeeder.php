<?php

namespace Database\Seeders;

use App\Actions\Banner\SaveBanner;
use App\Enums\BannerPosition;
use App\Models\Banner;
use Illuminate\Database\Seeder;

/**
 * The three front-page banners the storefront showed while they were still
 * hard-coded in `HomeController` (P1-E5), now rows an admin can edit
 * (P2-E8-T1), plus a promotional one and one scheduled for next week.
 *
 * Saved through `SaveBanner`, the action the admin form uses, with a desktop
 * and a mobile picture drawn by Faker (`DemoImages`). Without a reachable
 * image service, or under the test suite, they are saved without pictures —
 * the storefront draws such a banner as a plain card. Skipped per title, so
 * re-running never duplicates or overwrites an edited banner. Never run it
 * against a production database.
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
            [
                'title' => 'Flash Sale Elektronik 15%',
                'subtitle' => 'Headphone, keyboard, sampai smartwatch — hanya minggu ini.',
                'ends_at' => now()->addDays(5),
            ],
            [
                'title' => 'Promo Gajian Dapur',
                'subtitle' => 'Diskon 10% peralatan dapur, mulai minggu depan.',
                'starts_at' => now()->addWeek(),
                'ends_at' => now()->addWeeks(2),
            ],
        ];

        foreach ($banners as $position => $banner) {
            if (Banner::query()->where('title', $banner['title'])->exists()) {
                continue;
            }

            $image = DemoImages::upload($banner['title'], 1600, 600);
            $mobileImage = DemoImages::upload($banner['title'], 800, 800);

            $saveBanner->handle([
                'starts_at' => null,
                'ends_at' => null,
                ...$banner,
                'link_url' => $catalogue,
                'position' => BannerPosition::Hero,
                'branch_id' => null,
                'sort_order' => $position + 1,
                'is_active' => true,
            ], image: $image, mobileImage: $mobileImage);

            DemoImages::discard($image);
            DemoImages::discard($mobileImage);
        }
    }
}
