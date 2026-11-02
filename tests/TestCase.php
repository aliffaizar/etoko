<?php

namespace Tests;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Install the role and permission baseline with the test database.
     *
     * Roles and permissions are reference data that authorization throughout the
     * application depends on. RefreshDatabase runs this seeder as part of
     * `migrate:fresh`, which happens once per test process rather than per test.
     *
     * @var class-string<Seeder>
     */
    protected $seeder = RolePermissionSeeder::class;

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
