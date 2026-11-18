<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;

class DatabaseSeeder extends Seeder
{
    /** How long before today the demo shop is set up — before the order history starts. */
    private const SETUP_DAYS_AGO = 100;

    /**
     * Seed the application's database.
     *
     * **Model events stay on.** The starter kit shipped this seeder with
     * `WithoutModelEvents`, which silently switched off every observer the
     * catalogue depends on: `CategoryObserver` derives `categories.slug`
     * (NOT NULL, no default), `ProductObserver` and `ProductVariantObserver`
     * do the same for products and normalise SKU/barcode, `Auditable` writes
     * the audit trail BR-9 requires, and `StockRanLow` fires the low-stock
     * warning. With the trait in place `migrate:fresh --seed` died on the
     * first category; without it the chain runs and the audit trail is
     * written. Do not reintroduce it — see CRIT-01 in
     * docs/reviews/phase-1-review.md.
     *
     * RolePermissionSeeder, PaymentMethodSeeder and CourierSeeder are
     * reference data every environment needs — a shop with no payment methods
     * has a checkout that cannot be completed, and one with no courier has a
     * delivery option that cannot — so all three run unconditionally. The demo
     * seeders are sample content and are skipped in production.
     *
     * The order matters and is a chain of dependencies, not a preference:
     * inventory is received against variants, so the catalogue has to exist
     * first; and an order can only be placed for stock a branch actually
     * holds, so inventory has to exist before orders. Reviews and returns are
     * written about orders that arrived, so they come after the orders.
     *
     * **The shop is set up in the past.** `OrderHistoryDemoSeeder` places
     * three months of orders with the clock set back to each order's day, so
     * the accounts, the catalogue and the opening stock are created with the
     * clock set back further still (`SETUP_DAYS_AGO`). Otherwise the ledger
     * would show goods sold weeks before they were received, the stock card
     * would open on a negative shelf, and customers would order before they
     * registered. `OrderDemoSeeder` runs before the history because it skips a
     * branch that already has orders.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
        $this->call(PaymentMethodSeeder::class);
        $this->call(CourierSeeder::class);

        if (app()->isProduction()) {
            return;
        }

        Date::setTestNow(now()->subDays(self::SETUP_DAYS_AGO));

        try {
            $this->call(DemoDataSeeder::class);
            $this->call(CatalogDemoSeeder::class);
            $this->call(ExtendedCatalogDemoSeeder::class);
            $this->call(CustomerDemoSeeder::class);
            $this->call(InventoryDemoSeeder::class);
            $this->call(ExtendedInventoryDemoSeeder::class);
        } finally {
            Date::setTestNow();
        }

        $this->call(OrderDemoSeeder::class);
        $this->call(OrderHistoryDemoSeeder::class);
        $this->call(StockTransferDemoSeeder::class);
        $this->call(VoucherDemoSeeder::class);
        $this->call(PromotionDemoSeeder::class);
        $this->call(BannerDemoSeeder::class);
        $this->call(ReviewDemoSeeder::class);
        $this->call(ReturnDemoSeeder::class);
        $this->call(DemoImageSeeder::class);
    }
}
