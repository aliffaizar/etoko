<?php

use App\Exceptions\CheckoutFailedException;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockReservation;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use App\Models\Warehouse;
use Tests\Concurrency\ConcurrencyTestCase;

uses(ConcurrencyTestCase::class);

/*
 * The last unit of a voucher's quota (P2-E5-T4).
 *
 * `RedeemVoucher` locks the voucher row and reads the quota under it, so of
 * five customers checking out at once with a voucher that has one use left,
 * exactly one gets it. The other four are refused with a sentence and their
 * whole order rolls back — no order, no stock held — because the total they
 * agreed to had the discount in it.
 */

it('gives the last use of a voucher to exactly one of five simultaneous checkouts', function () {
    $branch = Branch::factory()->default()->create(['supports_pickup' => true]);
    $warehouse = Warehouse::factory()->for($branch)->default()->create();

    /** @var ProductVariant $variant */
    $variant = Product::factory()->withDefaultVariant()->create(['base_price' => 100_000])->variants()->sole();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity(50)->create();

    $voucher = Voucher::factory()->fixed(10_000)->quota(3, used: 2)->create();

    $results = $this->runWorkers('place-order-with-voucher.php', [
        $voucher->getKey(),
        $branch->getKey(),
        $variant->getKey(),
    ], copies: 5);

    expect($this->countSucceeded($results))->toBe(1)
        ->and($this->refusalsIn($results))->each->toBe(CheckoutFailedException::class)
        ->and($voucher->fresh()->used_count)->toBe(3)
        ->and(VoucherUsage::query()->live()->count())->toBe(1)
        ->and(Order::acrossBranches()->count())->toBe(1)
        ->and(Order::acrossBranches()->sole()->discount_total)->toBe(10_000)
        ->and((int) StockReservation::acrossBranches()->active()->sum('quantity'))->toBe(1)
        ->and(Stock::acrossBranches()->sole()->reserved_quantity)->toBe(1);
});
