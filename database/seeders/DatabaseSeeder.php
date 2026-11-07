<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * RolePermissionSeeder is reference data every environment needs; the four
     * demo seeders are sample content and are skipped in production.
     *
     * The order matters and is a chain of dependencies, not a preference:
     * inventory is received against variants, so the catalogue has to exist
     * first; and an order can only be placed for stock a branch actually
     * holds, so inventory has to exist before orders.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        if (! app()->isProduction()) {
            $this->call(DemoDataSeeder::class);
            $this->call(CatalogDemoSeeder::class);
            $this->call(InventoryDemoSeeder::class);
            $this->call(OrderDemoSeeder::class);
        }
    }
}
