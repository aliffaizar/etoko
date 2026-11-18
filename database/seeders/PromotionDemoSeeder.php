<?php

namespace Database\Seeders;

use App\Actions\Promotion\SavePromotion;
use App\Enums\PromotionType;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Product promotions for the demo (P2-E8-T2), so the storefront shows
 * strikethrough prices and the promotion screen has rows in every state: one
 * running everywhere, one running at a single branch, one scheduled for next
 * week and one that has ended.
 *
 * Saved through `SavePromotion`, which refreshes each product's lowest price
 * as the panel does. Skipped per name, so re-running never duplicates or
 * overwrites an edited promotion. Never run it against a production database.
 */
class PromotionDemoSeeder extends Seeder
{
    public function run(SavePromotion $savePromotion): void
    {
        $actor = User::query()->role(UserRole::AdminPusat->value)->first();
        $secondBranch = Branch::query()->where('is_default', false)->orderBy('id')->value('id');

        $promotions = [
            [
                'name' => 'Flash Sale Elektronik',
                'type' => PromotionType::Percentage,
                'value' => 15,
                'priority' => 10,
                'starts_at' => now()->subDays(2),
                'ends_at' => now()->addDays(5),
                'products' => $this->productsUnder('Elektronik', 6),
                'branches' => [],
            ],
            [
                'name' => 'Potongan Fesyen Akhir Pekan',
                'type' => PromotionType::Fixed,
                'value' => 25_000,
                'priority' => 5,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addDays(3),
                'products' => $this->productsUnder('Fesyen', 5),
                'branches' => $secondBranch === null ? [] : [(int) $secondBranch],
            ],
            [
                'name' => 'Promo Gajian Dapur',
                'type' => PromotionType::Percentage,
                'value' => 10,
                'priority' => 0,
                'starts_at' => now()->addWeek(),
                'ends_at' => now()->addWeeks(2),
                'products' => $this->productsUnder('Rumah Tangga', 5),
                'branches' => [],
            ],
            [
                'name' => 'Diskon Kopi Bulan Lalu',
                'type' => PromotionType::Percentage,
                'value' => 20,
                'priority' => 0,
                'starts_at' => now()->subMonth()->subWeek(),
                'ends_at' => now()->subMonth(),
                'products' => $this->productsUnder('Makanan & Minuman', 4),
                'branches' => [],
            ],
        ];

        foreach ($promotions as $promotion) {
            if ($promotion['products'] === [] || Promotion::query()->where('name', $promotion['name'])->exists()) {
                continue;
            }

            $savePromotion->handle([
                'attributes' => [
                    'name' => $promotion['name'],
                    'type' => $promotion['type'],
                    'value' => $promotion['value'],
                    'priority' => $promotion['priority'],
                    'starts_at' => $promotion['starts_at'],
                    'ends_at' => $promotion['ends_at'],
                    'is_active' => true,
                ],
                'product_ids' => $promotion['products'],
                'branch_ids' => $promotion['branches'],
            ], actor: $actor);
        }
    }

    /**
     * Up to `$limit` published products filed anywhere under the named root.
     *
     * @return array<int, int>
     */
    private function productsUnder(string $rootName, int $limit): array
    {
        $root = Category::query()->whereNull('parent_id')->where('name', $rootName)->first();

        if (! $root instanceof Category) {
            return [];
        }

        $categoryIds = [$root->getKey()];
        $frontier = [$root->getKey()];

        while ($frontier !== []) {
            $frontier = Category::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $categoryIds = [...$categoryIds, ...$frontier];
        }

        return Product::query()
            ->published()
            ->whereIn('category_id', $categoryIds)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
