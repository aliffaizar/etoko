<?php

namespace Database\Seeders;

use App\Actions\Order\PlaceOrder;
use App\Actions\Order\TransitionOrderStatus;
use App\Actions\Payment\InitiatePayment;
use App\Actions\Payment\VerifyPayment;
use App\Enums\FulfillmentType;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\User;
use App\Services\Payment\Data\PaymentContext;
use App\Services\Payment\Midtrans\MidtransClient;
use App\Services\Payment\PaymentMethodEligibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Three months of trade for the demo, so the dashboards and reports draw a
 * curve rather than a dot (P2-E7).
 *
 * Each order is placed, paid and walked through its statuses by the same
 * Actions `OrderDemoSeeder` uses — with the clock set back to the moment each
 * step happens. That is what makes the history honest: `placed_at`, `paid_at`,
 * the timeline, the payment rows and the `sale_out` ledger rows all carry the
 * day the order was made, so the sales chart, the stock card and the
 * best-seller list agree with each other the way they would after three real
 * months. A step whose moment would fall after the real "now" is not taken,
 * which leaves the most recent orders still being processed, shipped or
 * waiting for pickup.
 *
 * Orders lean towards recent days, so the chart rises. Roughly one in eight is
 * cancelled before payment; COD orders have their cash recorded on delivery.
 * The stock they sell must already be on the shelf on the day they are
 * placed, which is why `DatabaseSeeder` receives the opening stock with the
 * clock set before this window.
 *
 * Under the test suite only a handful of orders are made per branch: every
 * paid order renders an invoice PDF, and the suite runs its queue in-process.
 *
 * Re-running is safe: when any order older than a day exists, nothing is
 * added. Never run it against a production database.
 */
class OrderHistoryDemoSeeder extends OrderDemoSeeder
{
    /** How far back the history reaches, in days. */
    private const DAYS = 90;

    /** Orders per branch outside the test suite. */
    private const ORDERS_PER_BRANCH = 40;

    /** Orders per branch under the test suite. */
    private const ORDERS_PER_BRANCH_IN_TESTS = 4;

    /**
     * Notes a customer might leave at checkout.
     *
     * @var array<int, string|null>
     */
    private const NOTES = [
        null, null, null, null,
        'Tolong dibungkus rapi, untuk hadiah.',
        'Kirim siang hari ya, pagi tidak ada orang di rumah.',
        'Titip ke satpam kalau saya tidak ada.',
        'Mohon cek kelengkapan sebelum dikirim.',
    ];

    private CarbonImmutable $realNow;

    public function run(
        PlaceOrder $placeOrder,
        TransitionOrderStatus $transition,
        InitiatePayment $initiatePayment,
        VerifyPayment $verifyPayment,
        PaymentMethodEligibility $eligibility,
    ): void {
        if (Order::acrossBranches()->where('placed_at', '<', now()->subDay())->exists()) {
            return;
        }

        $customers = User::query()
            ->role(UserRole::Customer->value)
            ->where('is_active', true)
            ->get();

        if ($customers->isEmpty()) {
            $this->command?->warn('OrderHistoryDemoSeeder: belum ada akun pelanggan.');

            return;
        }

        $this->realNow = CarbonImmutable::now();
        $this->stubMidtrans();

        try {
            foreach (Branch::query()->active()->orderBy('id')->get() as $branch) {
                $variants = $this->variantsSoldAt($branch);

                if (count($variants) < 3) {
                    continue;
                }

                foreach ($this->moments() as $placedAt) {
                    $this->seedOrder(
                        $branch,
                        $customers,
                        $variants,
                        $placedAt,
                        $placeOrder,
                        $transition,
                        $initiatePayment,
                        $verifyPayment,
                        $eligibility,
                    );
                }
            }
        } finally {
            Date::setTestNow();
            app()->forgetInstance(MidtransClient::class);
        }
    }

    /**
     * One order, from checkout to wherever its moments reach.
     *
     * @param  Collection<int, User>  $customers
     * @param  array<int, ProductVariant>  $variants
     */
    private function seedOrder(
        Branch $branch,
        Collection $customers,
        array $variants,
        CarbonImmutable $placedAt,
        PlaceOrder $placeOrder,
        TransitionOrderStatus $transition,
        InitiatePayment $initiatePayment,
        VerifyPayment $verifyPayment,
        PaymentMethodEligibility $eligibility,
    ): void {
        $faker = fake();
        Date::setTestNow($placedAt);

        $order = $this->place(
            $placeOrder,
            $branch,
            $customers->random(),
            $faker->randomElements($variants, $faker->numberBetween(1, 3)),
            [
                'status' => null,
                'fulfillment' => $faker->boolean(30) ? FulfillmentType::Pickup : FulfillmentType::Delivery,
                'note' => $faker->randomElement(self::NOTES),
            ],
            $faker->numberBetween(1, 3),
        );

        if (! $order instanceof Order) {
            return;
        }

        $actor = $this->branchStaffOf($branch->getKey());
        $clock = $placedAt;

        if ($faker->boolean(12)) {
            $this->openPayment($order, ['midtrans-va', 'midtrans-qris'], $initiatePayment, $eligibility);

            $clock = $clock->addHours($faker->numberBetween(2, 20));

            if ($clock->lessThan($this->realNow)) {
                Date::setTestNow($clock);
                $transition->handle($order->refresh(), OrderStatus::Cancelled, null, 'Dibatalkan: pembayaran tidak diselesaikan.');
            }

            return;
        }

        $payment = $this->openPayment(
            $order,
            $order->fulfillment_type === FulfillmentType::Delivery && $faker->boolean(25)
                ? ['cod', 'midtrans-va']
                : $faker->shuffleArray(['midtrans-va', 'midtrans-qris', 'midtrans-ewallet', 'midtrans-va-bni']),
            $initiatePayment,
            $eligibility,
        );

        $isCod = $payment?->method_type->isCollectedOnDelivery() ?? false;
        $clock = $clock->addMinutes($faker->numberBetween(5, 90));
        Date::setTestNow($clock);

        if ($payment instanceof Payment && ! $isCod) {
            $verifyPayment->handle($payment);
        }

        $order->refresh();

        if ($order->status === OrderStatus::PendingPayment) {
            $transition->handle($order, OrderStatus::Paid, $actor);
        }

        foreach ($this->stepsFor($order) as [$status, $hours]) {
            $clock = $clock->addMinutes($hours * 60 + $faker->numberBetween(0, 59));

            if ($clock->greaterThanOrEqualTo($this->realNow)) {
                break;
            }

            Date::setTestNow($clock);
            $order = $transition->handle($order->refresh(), $status, $actor);

            if ($isCod && $status === OrderStatus::Delivered) {
                $verifyPayment->handle($payment->refresh(), $actor, 'Uang COD diterima dari kurir.');
            }
        }
    }

    /**
     * The moves after `paid`, each with how many hours it typically takes.
     *
     * @return array<int, array{0: OrderStatus, 1: int}>
     */
    private function stepsFor(Order $order): array
    {
        $faker = fake();

        return $order->fulfillment_type === FulfillmentType::Pickup
            ? [
                [OrderStatus::Processing, $faker->numberBetween(1, 3)],
                [OrderStatus::ReadyForPickup, $faker->numberBetween(2, 6)],
                [OrderStatus::Completed, $faker->numberBetween(4, 48)],
            ]
            : [
                [OrderStatus::Processing, $faker->numberBetween(1, 3)],
                [OrderStatus::Shipped, $faker->numberBetween(6, 24)],
                [OrderStatus::Delivered, $faker->numberBetween(24, 72)],
                [OrderStatus::Completed, $faker->numberBetween(24, 72)],
            ];
    }

    /**
     * Open an attempt with the first of the given methods the order may use.
     *
     * @param  array<int, string>  $codes
     */
    private function openPayment(
        Order $order,
        array $codes,
        InitiatePayment $initiatePayment,
        PaymentMethodEligibility $eligibility,
    ): ?Payment {
        foreach ($codes as $code) {
            $method = PaymentMethod::query()->where('code', $code)->first();

            if (! $method instanceof PaymentMethod
                || ! $eligibility->allows($method, PaymentContext::forOrder($order))) {
                continue;
            }

            try {
                return $initiatePayment->handle($order, $method);
            } catch (\RuntimeException $refused) {
                $this->command?->warn("OrderHistoryDemoSeeder: {$refused->getMessage()}");

                return null;
            }
        }

        return null;
    }

    /**
     * When each order is placed, oldest first, leaning towards recent days so
     * the sales chart rises. Shop hours only, and never in the last hour.
     *
     * @return array<int, CarbonImmutable>
     */
    private function moments(): array
    {
        $faker = fake();
        $count = app()->runningUnitTests() ? self::ORDERS_PER_BRANCH_IN_TESTS : self::ORDERS_PER_BRANCH;
        $latest = $this->realNow->subHour();
        $moments = [];

        for ($index = 0; $index < $count; $index++) {
            $daysAgo = (int) floor(self::DAYS * (1 - sqrt($faker->randomFloat(4, 0, 1))));

            $moment = $this->realNow
                ->subDays($daysAgo)
                ->setTime($faker->numberBetween(8, 21), $faker->numberBetween(0, 59));

            $moments[] = $moment->greaterThan($latest) ? $latest->subMinutes($faker->numberBetween(0, 120)) : $moment;
        }

        usort($moments, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        return $moments;
    }

    /**
     * Variants the branch holds plenty of and is allowed to sell.
     *
     * @return array<int, ProductVariant>
     */
    private function variantsSoldAt(Branch $branch): array
    {
        $unavailable = BranchProduct::acrossBranches()
            ->where('branch_id', $branch->getKey())
            ->unavailable()
            ->pluck('product_id');

        $variantIds = Stock::acrossBranches()
            ->where('branch_id', $branch->getKey())
            ->whereRaw('quantity - reserved_quantity >= 20')
            ->pluck('product_variant_id');

        return ProductVariant::query()
            ->with('product')
            ->whereKey($variantIds)
            ->whereNotIn('product_id', $unavailable)
            ->whereHas('product', fn ($product) => $product->published())
            ->get()
            ->all();
    }
}
