<?php

namespace Database\Seeders;

use App\Actions\Order\PlaceOrder;
use App\Actions\Order\TransitionOrderStatus;
use App\Actions\Payment\InitiatePayment;
use App\Actions\Payment\VerifyPayment;
use App\Enums\FulfillmentType;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\User;
use App\Services\Payment\Data\PaymentContext;
use App\Services\Payment\Midtrans\MidtransClient;
use App\Services\Payment\PaymentMethodEligibility;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Example orders for local development (P1-E8-T1).
 *
 * Built through `PlaceOrder` and `TransitionOrderStatus` — the same two actions
 * the storefront and the panel use — so what the seeder produces can never
 * drift from what the application produces. That matters more here than
 * anywhere: an order's stock holds, its ledger rows and its timeline all come
 * from those actions, and a seeder that wrote `orders` directly would leave
 * behind orders holding no stock, with no history, in states the state machine
 * cannot reach. `OrderDemoSeederTest` locks that invariant.
 *
 * The set of orders is chosen so every screen has something to show:
 *
 * - one awaiting payment, **holding stock** — so `/branch/stocks` shows a
 *   reserved figure and the order detail screen shows a live hold;
 *   its `expires_at` is pushed well out so the expiry sweep does not
 *   immediately cancel the demo data;
 * - one COD, confirmed and being processed, with the money still outstanding;
 * - one completed, paid through the gateway and verified, for the history and
 *   the customer's lifetime total;
 * - one cancelled, with its hold released and its payment attempt closed.
 *
 * **Payments are seeded through the real actions too** (`P2-E1-T1`), for the
 * same reason as the orders: `/admin/payments` is a queue, and a queue with
 * nothing in it demonstrates nothing. The four scenarios leave one gateway
 * attempt awaiting payment, one COD awaiting cash, one settled and one
 * expired — which is every column of the payment screen with something in it.
 * No receipt file is faked: uploading one means writing to the disk, and a
 * demo image that looks like a real payment slip is worse than an empty field.
 *
 * **Midtrans is stubbed, not called** (ADR-051, ADR-053). Online payment is
 * Midtrans, so seeding realistic demo payments means opening gateway attempts
 * — and a seeder that reaches a payment provider is one that needs the
 * network, takes a visible pause per order, and leaves real sandbox
 * transactions behind every time somebody runs `migrate:fresh --seed`. The
 * stub below stands in for `MidtransClient` inside the seeding process only.
 * An install with no `MIDTRANS_SERVER_KEY` never gets that far: the methods
 * are seeded inactive, `InitiatePayment` refuses, and `pay()` warns and moves
 * on — demo orders without payments, which is a degradation rather than a
 * failure.
 *
 * Re-running it is safe: a branch that already has orders is skipped rather
 * than given more. Never run it against a production database.
 */
class OrderDemoSeeder extends Seeder
{
    /** Units per line, small enough to leave plenty on the shelf. */
    private const QUANTITY = 2;

    /** Lines per order, so the detail screens are not single-row. */
    private const LINES_PER_ORDER = 2;

    /**
     * How the demo orders are left, in the order they are created.
     *
     * Each is reached by walking the real state machine from
     * `pending_payment`, which is why the paths are written out rather than
     * assigned: `completed` is four moves away, and every one of them has
     * consequences for stock.
     *
     * `payment` names the method code to open an attempt with, and `settle`
     * says whether the money is then recorded as received. A settled attempt
     * is what moves its order to `paid`, so the paths below start from
     * `processing` rather than repeating that step — walking to `paid` a
     * second time would be an illegal transition, which is the state machine
     * correctly refusing to be paid twice.
     *
     * @var array<int, array{status: OrderStatus|null, fulfillment: FulfillmentType, note: string, payment: string|null, settle: bool}>
     */
    private const SCENARIOS = [
        [
            'status' => null,
            'fulfillment' => FulfillmentType::Delivery,
            'note' => 'Menunggu pembayaran — stoknya masih ditahan.',
            'payment' => 'midtrans-va',
            'settle' => false,
        ],
        [
            'status' => OrderStatus::Processing,
            'fulfillment' => FulfillmentType::Delivery,
            'note' => 'COD — barang disiapkan, uang ditagih saat diantar.',
            'payment' => 'cod',
            'settle' => false,
        ],
        [
            'status' => OrderStatus::Completed,
            'fulfillment' => FulfillmentType::Pickup,
            'note' => 'Selesai, diambil sendiri di cabang.',
            'payment' => 'midtrans-va',
            'settle' => true,
        ],
        [
            'status' => OrderStatus::Cancelled,
            'fulfillment' => FulfillmentType::Delivery,
            'note' => 'Dibatalkan pelanggan sebelum dibayar.',
            'payment' => 'midtrans-va',
            'settle' => false,
        ],
    ];

    public function run(
        PlaceOrder $placeOrder,
        TransitionOrderStatus $transition,
        InitiatePayment $initiatePayment,
        VerifyPayment $verifyPayment,
        PaymentMethodEligibility $eligibility,
    ): void {
        $this->stubMidtrans();

        $customers = $this->customers();

        if ($customers->isEmpty()) {
            $this->command?->warn('OrderDemoSeeder: belum ada akun pelanggan. Jalankan DemoDataSeeder lebih dahulu.');

            return;
        }

        foreach (Branch::query()->active()->orderBy('id')->get() as $branch) {
            if (Order::acrossBranches()->where('branch_id', $branch->getKey())->exists()) {
                continue;
            }

            $this->seedBranch(
                $branch,
                $customers,
                $placeOrder,
                $transition,
                $initiatePayment,
                $verifyPayment,
                $eligibility,
            );
        }

        self::extendDemoHolds();

        app()->forgetInstance(MidtransClient::class);
    }

    /**
     * Answer Midtrans locally, so seeding never leaves the machine.
     *
     * Only `charge()` is answered, in the shape the Core API uses for the
     * channel that was asked for. The numbers are obviously fake, so a demo
     * payment row looks like the real thing on the payment screen without any
     * transaction existing at Midtrans — which is also what the status API
     * will say about it afterwards: nothing recorded, nothing to change.
     */
    private function stubMidtrans(): void
    {
        app()->instance(MidtransClient::class, new class extends MidtransClient
        {
            public function charge(array $params): array
            {
                $answer = [
                    'status_code' => '201',
                    'transaction_id' => 'demo-seeder-'.$params['transaction_details']['order_id'],
                    'order_id' => $params['transaction_details']['order_id'],
                    'transaction_status' => 'pending',
                    'payment_type' => $params['payment_type'],
                ];

                return match ($params['payment_type']) {
                    'bank_transfer' => [...$answer, 'va_numbers' => [[
                        'bank' => $params['bank_transfer']['bank'],
                        'va_number' => '0000000000000000',
                    ]]],
                    'qris' => [...$answer, 'qr_string' => 'DEMO-SEEDER-QRIS'],
                    default => [...$answer, 'actions' => [[
                        'name' => 'deeplink-redirect',
                        'method' => 'GET',
                        'url' => 'https://simulator.sandbox.midtrans.com/demo-seeder',
                    ]]],
                };
            }
        });
    }

    /**
     * @param  Collection<int, User>  $customers
     */
    private function seedBranch(
        Branch $branch,
        Collection $customers,
        PlaceOrder $placeOrder,
        TransitionOrderStatus $transition,
        InitiatePayment $initiatePayment,
        VerifyPayment $verifyPayment,
        PaymentMethodEligibility $eligibility,
    ): void {
        $variants = $this->sellableVariantsAt($branch);

        if (count($variants) < self::LINES_PER_ORDER) {
            $this->command?->warn(
                "OrderDemoSeeder: cabang {$branch->name} belum punya stok yang cukup. Jalankan InventoryDemoSeeder lebih dahulu.",
            );

            return;
        }

        foreach (self::SCENARIOS as $index => $scenario) {
            $customer = $customers[$index % $customers->count()];
            $lines = array_slice($variants, $index % 2, self::LINES_PER_ORDER);

            if (count($lines) < self::LINES_PER_ORDER) {
                $lines = array_slice($variants, 0, self::LINES_PER_ORDER);
            }

            $order = $this->place($placeOrder, $branch, $customer, $lines, $scenario);

            if ($order === null) {
                continue;
            }

            $this->pay($order, $scenario, $initiatePayment, $verifyPayment, $eligibility);

            $this->walkTo($transition, $order->refresh(), $scenario['status'], $scenario['note']);
        }
    }

    /**
     * Fill a cart and check it out as the customer would.
     *
     * Returns null when the branch turned out not to be able to cover it —
     * better a seeder with fewer example orders than a seeder that dies
     * halfway and leaves the database partly filled.
     *
     * @param  array<int, ProductVariant>  $lines
     * @param  array{status: OrderStatus|null, fulfillment: FulfillmentType, note: string}  $scenario
     */
    private function place(
        PlaceOrder $placeOrder,
        Branch $branch,
        User $customer,
        array $lines,
        array $scenario,
    ): ?Order {
        $fulfillment = $this->supportedFulfillment($branch, $scenario['fulfillment']);
        $address = $this->addressOf($customer, $fulfillment);

        if ($fulfillment === null || ($fulfillment->requiresShipping() && $address === null)) {
            return null;
        }

        $cart = $this->filledCart($customer, $branch, $lines);

        try {
            return $placeOrder->handle($cart, $branch, $customer, [
                'fulfillment_type' => $fulfillment,
                'address_id' => $address?->getKey(),
                'customer_note' => $scenario['note'],
            ]);
        } catch (\RuntimeException $refused) {
            $this->command?->warn("OrderDemoSeeder: {$refused->getMessage()}");
            $cart->items()->delete();

            return null;
        }
    }

    /**
     * Walk the order to the status the scenario wants, one legal move at a
     * time.
     *
     * Each step is a real transition, so the stock, the timestamps and the
     * timeline all end up where they would in production.
     */
    private function walkTo(
        TransitionOrderStatus $transition,
        Order $order,
        ?OrderStatus $target,
        string $note,
    ): void {
        if ($target === null) {
            return;
        }

        // `paid` is prepended only when the payment above did not already get
        // the order there — a settled transfer and a confirmed COD both do
        // (ADR-040), and asking for the same move twice is an illegal
        // transition.
        $prefix = $order->status === OrderStatus::PendingPayment && $target !== OrderStatus::Cancelled
            ? [OrderStatus::Paid]
            : [];

        $path = match ($target) {
            OrderStatus::Cancelled => [OrderStatus::Cancelled],
            OrderStatus::Processing => [...$prefix, OrderStatus::Processing],
            OrderStatus::Completed => $order->fulfillment_type === FulfillmentType::Pickup
                ? [...$prefix, OrderStatus::Processing, OrderStatus::ReadyForPickup, OrderStatus::Completed]
                : [...$prefix, OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Completed],
            default => [$target],
        };

        $actor = $this->branchStaffOf($order->branch_id);

        foreach ($path as $step) {
            $order = $transition->handle($order, $step, $actor, $note);
        }
    }

    /**
     * Open the scenario's payment attempt, and settle it when it says to.
     *
     * The method is checked for eligibility first and quietly skipped when it
     * does not apply — a demo branch that does not deliver cannot take COD,
     * and a demo order above the COD ceiling should not be given one. Better a
     * seeder that leaves one order unpaid than one that dies on a rule it is
     * meant to be demonstrating.
     *
     * @param  array{status: OrderStatus|null, fulfillment: FulfillmentType, note: string, payment: string|null, settle: bool}  $scenario
     */
    private function pay(
        Order $order,
        array $scenario,
        InitiatePayment $initiatePayment,
        VerifyPayment $verifyPayment,
        PaymentMethodEligibility $eligibility,
    ): void {
        if ($scenario['payment'] === null) {
            return;
        }

        $method = PaymentMethod::query()->where('code', $scenario['payment'])->first();

        if (! $method instanceof PaymentMethod
            || ! $eligibility->allows($method, PaymentContext::forOrder($order))) {
            return;
        }

        try {
            $payment = $initiatePayment->handle($order, $method);

            if ($scenario['settle']) {
                $verifyPayment->handle($payment, $this->branchStaffOf($order->branch_id));
            }
        } catch (\RuntimeException $refused) {
            $this->command?->warn("OrderDemoSeeder: {$refused->getMessage()}");
        }
    }

    /**
     * A cart with the given variants in it, priced the way the storefront
     * prices them: the catalogue price, with no branch (ADR-031).
     *
     * @param  array<int, ProductVariant>  $lines
     */
    private function filledCart(User $customer, Branch $branch, array $lines): Cart
    {
        $cart = Cart::firstOrCreate(
            ['user_id' => $customer->getKey()],
            ['session_token' => null, 'expires_at' => null],
        );

        $cart->update(['branch_id' => $branch->getKey()]);
        $cart->items()->delete();

        foreach ($lines as $variant) {
            $cart->items()->create([
                'product_variant_id' => $variant->getKey(),
                'quantity' => self::QUANTITY,
                'unit_price_snapshot' => $variant->effectivePrice(),
            ]);
        }

        return $cart->fresh(['items']);
    }

    /**
     * Variants the branch can actually sell right now, most-stocked first.
     *
     * Read through `stocks` rather than through the catalogue, because what
     * matters here is that the demo order can really be placed — an order for
     * something the branch does not hold would be refused, which is correct
     * behaviour and a useless demo.
     *
     * @return array<int, ProductVariant>
     */
    private function sellableVariantsAt(Branch $branch): array
    {
        $variantIds = Stock::acrossBranches()
            ->where('branch_id', $branch->getKey())
            ->whereColumn('quantity', '>', 'reserved_quantity')
            ->orderByDesc('quantity')
            ->limit(8)
            ->pluck('product_variant_id');

        return ProductVariant::query()
            ->with('product')
            ->whereKey($variantIds)
            ->whereHas('product', fn ($product) => $product->published())
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * The fulfilment the scenario asked for, or whatever the branch does
     * support instead.
     */
    private function supportedFulfillment(Branch $branch, FulfillmentType $preferred): ?FulfillmentType
    {
        $supports = fn (FulfillmentType $type): bool => match ($type) {
            FulfillmentType::Delivery => $branch->supports_delivery,
            FulfillmentType::Pickup => $branch->supports_pickup,
        };

        if ($supports($preferred)) {
            return $preferred;
        }

        foreach (FulfillmentType::cases() as $type) {
            if ($supports($type)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * An address for the customer, created if they have none, so a delivery
     * order has somewhere to go.
     */
    private function addressOf(User $customer, ?FulfillmentType $fulfillment): ?Address
    {
        if ($fulfillment === null || ! $fulfillment->requiresShipping()) {
            return null;
        }

        return $customer->addresses()->orderByDesc('is_default')->first()
            ?? Address::factory()->for($customer)->create(['is_default' => true]);
    }

    /**
     * @return Collection<int, User>
     */
    private function customers(): Collection
    {
        return User::query()
            ->role(UserRole::Customer->value)
            ->where('is_active', true)
            ->orderBy('id')
            ->limit(4)
            ->get();
    }

    /**
     * Somebody from the branch to put on the status changes, so the timeline
     * does not read as though nobody was involved.
     */
    private function branchStaffOf(int $branchId): ?User
    {
        return User::query()
            ->where('branch_id', $branchId)
            ->whereHas('roles', fn ($roles) => $roles->where('name', UserRole::AdminCabang->value))
            ->first();
    }

    /**
     * Push the unpaid demo order's hold well into the future.
     *
     * Without this, `ExpireStockReservations` would cancel the one order that
     * exists to show a live hold within fifteen minutes of seeding — and the
     * screen it exists for would be empty by the time anyone looked.
     *
     * Written with the query builder on purpose: this is a seeder convenience,
     * not a business rule, and it moves no figure.
     */
    public static function extendDemoHolds(): void
    {
        DB::table('stock_reservations')
            ->where('status', 'active')
            ->update(['expires_at' => now()->addDays(30)]);
    }
}
