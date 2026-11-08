<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
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
     * RolePermissionSeeder and PaymentMethodSeeder are reference data every
     * environment needs — a shop with no payment methods has a checkout that
     * cannot be completed — so both run unconditionally. The four demo seeders
     * are sample content and are skipped in production.
     *
     * The order matters and is a chain of dependencies, not a preference:
     * inventory is received against variants, so the catalogue has to exist
     * first; and an order can only be placed for stock a branch actually
     * holds, so inventory has to exist before orders.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
        $this->call(PaymentMethodSeeder::class);

        if (! app()->isProduction()) {
            $this->call(DemoDataSeeder::class);
            $this->call(CatalogDemoSeeder::class);
            $this->call(InventoryDemoSeeder::class);
            $this->call(OrderDemoSeeder::class);
        }
    }
}
