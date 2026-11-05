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
     * RolePermissionSeeder is reference data every environment needs;
     * DemoDataSeeder, CatalogDemoSeeder and InventoryDemoSeeder are sample
     * content and are skipped in production.
     *
     * The order matters: inventory is received against variants, so the
     * catalogue has to exist first.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        if (! app()->isProduction()) {
            $this->call(DemoDataSeeder::class);
            $this->call(CatalogDemoSeeder::class);
            $this->call(InventoryDemoSeeder::class);
        }
    }
}
