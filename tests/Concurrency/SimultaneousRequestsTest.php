<?php

use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\ConcurrencyTestCase;

uses(ConcurrencyTestCase::class);

/*
 * Two requests at once (MED-07, BR-6).
 *
 * Required by `06-conventions.md` §Testing and missing until now, which is how
 * three concurrency bugs — HIGH-01, HIGH-02, HIGH-03 — passed 1,195 green
 * tests. Each test here launches real processes against MySQL and asserts the
 * outcome the single-threaded suite cannot reach; on any other driver they
 * skip themselves. See `ConcurrencyTestCase` for why.
 */

/**
 * A branch holding exactly `$onHand` units of one variant.
 *
 * @return array{0: Branch, 1: ProductVariant}
 */
function branchHolding(int $onHand): array
{
    $branch = Branch::factory()->default()->create([
        'supports_delivery' => true,
        'supports_pickup' => true,
    ]);
    $warehouse = Warehouse::factory()->for($branch)->default()->create();

    /** @var ProductVariant $variant */
    $variant = Product::factory()->withDefaultVariant()->create(['base_price' => 179_000])
        ->variants()
        ->sole();

    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity($onHand)->create();

    return [$branch, $variant];
}

it('lets exactly one of five simultaneous requests take the last unit', function () {
    [$branch, $variant] = branchHolding(1);

    $results = $this->runWorkers('reserve-stock.php', [
        $branch->getKey(),
        $variant->getKey(),
        1,
    ], copies: 5);

    // The guarantee the whole of Phase 1 rests on: the row lock decides, and
    // the four that lose are refused rather than driving the figure negative.
    expect($this->countSucceeded($results))->toBe(1)
        ->and($this->refusalsIn($results))
        ->each->toBe(InsufficientStockException::class);

    $stock = Stock::acrossBranches()->sole();

    expect($stock->quantity)->toBe(1)
        ->and($stock->reserved_quantity)->toBe(1)
        ->and($stock->available_quantity)->toBe(0)
        ->and((int) StockReservation::acrossBranches()->active()->sum('quantity'))->toBe(1);
});

it('hands out every unit and not one more when demand exceeds stock', function () {
    [$branch, $variant] = branchHolding(3);

    $results = $this->runWorkers('reserve-stock.php', [
        $branch->getKey(),
        $variant->getKey(),
        1,
    ], copies: 8);

    expect($this->countSucceeded($results))->toBe(3);

    $stock = Stock::acrossBranches()->sole();

    expect($stock->reserved_quantity)->toBe(3)
        ->and($stock->available_quantity)->toBe(0)
        ->and((int) StockReservation::acrossBranches()->active()->sum('quantity'))->toBe(3)
        ->and(DB::table('stocks')->whereColumn('reserved_quantity', '>', 'quantity')->count())->toBe(0);
});

it('turns one cart into one order, however many requests arrive (HIGH-01)', function () {
    [$branch, $variant] = branchHolding(10);
    $customer = User::factory()->customer()->create();
    $cart = Cart::factory()->forUser($customer)->create(['branch_id' => $branch->getKey()]);
    CartItem::factory()->forCart($cart)->forVariant($variant)->create([
        'quantity' => 2,
        'unit_price_snapshot' => 179_000,
    ]);

    $results = $this->runWorkers('place-order.php', [
        $cart->getKey(),
        $branch->getKey(),
        $customer->getKey(),
    ], copies: 3);

    // Before the cart was locked, three parallel requests produced two orders
    // of Rp358.000 each and held four units for a basket of two.
    expect($this->countSucceeded($results))->toBe(1)
        ->and(Order::acrossBranches()->count())->toBe(1)
        ->and((int) Order::acrossBranches()->sum('grand_total'))->toBe(358_000)
        ->and(Stock::acrossBranches()->sole()->reserved_quantity)->toBe(2)
        ->and((int) StockReservation::acrossBranches()->active()->sum('quantity'))->toBe(2)
        ->and(CartItem::count())->toBe(0);
});

it('gives five simultaneous callers five document numbers on a counter that does not exist yet (HIGH-02)', function () {
    DB::table('document_counters')->where('prefix', DocumentNumber::PREFIX_ORDER)->delete();

    $results = $this->runWorkers(
        'next-document-number.php',
        [DocumentNumber::PREFIX_ORDER],
        copies: 5,
    );

    // The first document of each prefix each day used to deadlock: a locking
    // read that matched nothing took a gap lock, and the inserts that followed
    // waited on each other inside it. Four of five callers got an HTTP 500.
    expect($this->refusalsIn($results))->toBe([])
        ->and($this->countSucceeded($results))->toBe(5);

    $numbers = array_map(fn (array $result): string => (string) $result['number'], $results);

    expect(array_unique($numbers))->toHaveCount(5)
        ->and((int) DB::table('document_counters')
            ->where('prefix', DocumentNumber::PREFIX_ORDER)
            ->value('last_number'))->toBe(5);
});

it('keeps numbering uniquely once the counter is there', function () {
    DB::table('document_counters')->where('prefix', DocumentNumber::PREFIX_ORDER)->delete();
    DocumentNumber::next(DocumentNumber::PREFIX_ORDER);

    $results = $this->runWorkers(
        'next-document-number.php',
        [DocumentNumber::PREFIX_ORDER],
        copies: 8,
    );

    $numbers = array_map(fn (array $result): ?string => $result['number'] ?? null, $results);

    expect($this->refusalsIn($results))->toBe([])
        ->and(array_unique($numbers))->toHaveCount(8);
});
