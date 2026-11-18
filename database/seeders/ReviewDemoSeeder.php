<?php

namespace Database\Seeders;

use App\Actions\Review\ModerateReview;
use App\Actions\Review\ReplyToReview;
use App\Actions\Review\WriteReview;
use App\Enums\OrderStatus;
use App\Enums\ReviewStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Review;
use App\Models\User;
use App\Scopes\BranchScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;

/**
 * Product reviews for the demo (P2-E8-T3), written by the customers who
 * actually received the goods.
 *
 * About half the lines of delivered and completed orders get a review, a day
 * or two after the order arrived. Most are approved, some still wait in the
 * moderation queue and a few were rejected, so `/admin/reviews` has every tab
 * filled; a share of the approved ones carry a reply from the shop, and a few
 * a photo drawn by Faker (`DemoImages`).
 *
 * Through `WriteReview`, `ModerateReview` and `ReplyToReview` — the actions
 * the storefront and the panel use — so the verified-purchase rule holds and
 * each product's rating is recomputed from what is approved.
 *
 * Skipped when any review exists. Never run it against a production database.
 */
class ReviewDemoSeeder extends Seeder
{
    /**
     * Rating => [title, comment] pairs a customer might write.
     *
     * @var array<int, array<int, array{0: string, 1: string}>>
     */
    private const TEXTS = [
        5 => [
            ['Sangat puas!', 'Barang sesuai deskripsi, kualitasnya bagus banget. Pengiriman juga cepat. Pasti belanja lagi di sini.'],
            ['Mantap, recommended', 'Kemasan rapi dan aman, produk berfungsi dengan baik. Terima kasih, seller!'],
            ['Kualitas juara', 'Sudah dipakai seminggu dan tidak ada masalah sama sekali. Harga sepadan dengan kualitasnya.'],
            ['Pelayanan ramah', 'Ambil di cabang cepat, petugasnya ramah dan membantu cek barang dulu sebelum dibawa pulang.'],
        ],
        4 => [
            ['Bagus', 'Produk bagus dan sesuai foto. Hanya saja pengirimannya sedikit lebih lama dari perkiraan.'],
            ['Cukup memuaskan', 'Secara keseluruhan oke. Ada sedikit goresan di kemasan, tapi barangnya aman.'],
            ['Worth it', 'Untuk harga segini sudah sangat baik. Semoga awet.'],
        ],
        3 => [
            ['Lumayan', 'Fungsinya sesuai, tapi kualitas bahannya biasa saja. Sesuai harga lah.'],
            ['Standar', 'Tidak jelek, tidak istimewa juga. Ukurannya sedikit berbeda dari bayangan saya.'],
        ],
        2 => [
            ['Kurang sesuai', 'Warnanya agak berbeda dari foto di katalog. Semoga ke depan fotonya lebih akurat.'],
        ],
        1 => [
            ['Kecewa', 'Barang datang dalam kondisi penyok di salah satu sisi. Sudah saya ajukan retur.'],
        ],
    ];

    /**
     * Replies the shop might give an approved review.
     *
     * @var array<int, string>
     */
    private const REPLIES = [
        'Terima kasih atas ulasannya, Kak! Ditunggu belanja berikutnya ya.',
        'Senang mendengar produknya sesuai harapan. Terima kasih sudah berbelanja di eToko.',
        'Terima kasih atas masukannya, Kak. Akan kami sampaikan ke tim gudang agar pengemasan lebih baik lagi.',
    ];

    public function run(WriteReview $writeReview, ModerateReview $moderate, ReplyToReview $reply): void
    {
        if (Review::query()->exists()) {
            return;
        }

        $replier = User::query()->role(UserRole::AdminPusat->value)->first();
        $realNow = CarbonImmutable::now();
        $faker = fake();

        $items = OrderItem::query()
            ->whereHas('order', fn ($order) => $order->withoutGlobalScope(BranchScope::class)
                ->whereIn('status', [OrderStatus::Delivered, OrderStatus::Completed]))
            ->with(['order' => fn ($order) => $order->withoutGlobalScope(BranchScope::class)])
            ->whereNotNull('product_id')
            ->orderBy('id')
            ->get();

        try {
            foreach ($items as $item) {
                if (! $faker->boolean(55)) {
                    continue;
                }

                $order = $item->order;
                $customer = User::query()->find($order->user_id);

                if (! $customer instanceof User) {
                    continue;
                }

                $writtenAt = $this->momentAfter($order, $realNow);
                Date::setTestNow($writtenAt);

                $rating = $this->rating();
                [$title, $comment] = $faker->randomElement(self::TEXTS[$rating]);

                $photo = $rating >= 4 && $faker->boolean(20)
                    ? DemoImages::store(Review::IMAGE_DIRECTORY.'/'.$item->getKey(), $item->product_name, 640, 640)
                    : null;

                try {
                    $review = $writeReview->handle($customer, $item, [
                        'rating' => $rating,
                        'title' => $title,
                        'comment' => $comment,
                    ], array_filter([$photo]));
                } catch (ValidationException) {
                    continue;
                }

                $status = $faker->randomElement([
                    ...array_fill(0, 16, ReviewStatus::Approved),
                    ...array_fill(0, 3, ReviewStatus::Pending),
                    ReviewStatus::Rejected,
                ]);

                // The newest reviews are the ones still waiting for a moderator.
                if ($writtenAt->greaterThan($realNow->subDays(2))) {
                    $status = ReviewStatus::Pending;
                }

                if ($status === ReviewStatus::Pending) {
                    continue;
                }

                Date::setTestNow($this->cap($writtenAt->addHours($faker->numberBetween(2, 20)), $realNow));
                $review = $moderate->handle($review, $status);

                if ($status === ReviewStatus::Approved && $replier instanceof User && $faker->boolean(35)) {
                    $reply->handle($review, $faker->randomElement(self::REPLIES), $replier);
                }
            }
        } finally {
            Date::setTestNow();
        }
    }

    /**
     * Mostly happy customers, as a real shop's ratings lean.
     */
    private function rating(): int
    {
        return fake()->randomElement([5, 5, 5, 5, 5, 4, 4, 4, 3, 3, 2, 1]);
    }

    /**
     * A day or two after the goods arrived, never later than now.
     */
    private function momentAfter(Order $order, CarbonImmutable $realNow): CarbonImmutable
    {
        $arrived = CarbonImmutable::parse($order->completed_at ?? $order->updated_at);

        return $this->cap($arrived->addHours(fake()->numberBetween(6, 48)), $realNow);
    }

    private function cap(CarbonImmutable $moment, CarbonImmutable $realNow): CarbonImmutable
    {
        $latest = $realNow->subMinutes(10);

        return $moment->greaterThan($latest) ? $latest : $moment;
    }
}
