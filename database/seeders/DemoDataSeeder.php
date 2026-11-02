<?php

namespace Database\Seeders;

use App\Actions\Branch\CreateBranch;
use App\Enums\BranchStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo accounts and branches for local development.
 *
 * Credentials are listed in docs/07-progress.md. Never run this seeder against
 * a production database.
 */
class DemoDataSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public function run(CreateBranch $createBranch): void
    {
        $branches = collect([
            [
                'code' => 'JKT-01',
                'name' => 'Cabang Jakarta Pusat',
                'phone' => '02150001000',
                'email' => 'jakarta@etoko.test',
                'province_name' => 'DKI Jakarta',
                'city_name' => 'Jakarta Pusat',
                'district_name' => 'Menteng',
                'postal_code' => '10310',
                'address_line' => 'Jl. M.H. Thamrin No. 1',
                'latitude' => -6.195,
                'longitude' => 106.823,
            ],
            [
                'code' => 'BDG-01',
                'name' => 'Cabang Bandung',
                'phone' => '02250002000',
                'email' => 'bandung@etoko.test',
                'province_name' => 'Jawa Barat',
                'city_name' => 'Bandung',
                'district_name' => 'Coblong',
                'postal_code' => '40132',
                'address_line' => 'Jl. Ir. H. Juanda No. 100',
                'latitude' => -6.893,
                'longitude' => 107.610,
            ],
            [
                'code' => 'SBY-01',
                'name' => 'Cabang Surabaya',
                'phone' => '03150003000',
                'email' => 'surabaya@etoko.test',
                'province_name' => 'Jawa Timur',
                'city_name' => 'Surabaya',
                'district_name' => 'Gubeng',
                'postal_code' => '60281',
                'address_line' => 'Jl. Raya Gubeng No. 20',
                'latitude' => -7.265,
                'longitude' => 112.751,
            ],
        ])->map(fn (array $attributes): Branch => $createBranch->handle([
            ...$attributes,
            'status' => BranchStatus::Active,
            'supports_delivery' => true,
            'supports_pickup' => true,
        ]));

        $this->createStaff('Super Admin', 'superadmin@etoko.test', UserRole::SuperAdmin);
        $this->createStaff('Admin Pusat', 'adminpusat@etoko.test', UserRole::AdminPusat);

        foreach ($branches as $index => $branch) {
            $suffix = $index + 1;

            $this->createStaff("Admin {$branch->name}", "admincabang{$suffix}@etoko.test", UserRole::AdminCabang, $branch);
            $this->createStaff("Gudang {$branch->name}", "gudang{$suffix}@etoko.test", UserRole::StaffGudang, $branch);
            $this->createStaff("Kasir {$branch->name}", "kasir{$suffix}@etoko.test", UserRole::Kasir, $branch);
        }

        User::factory()
            ->customer()
            ->create(['name' => 'Pelanggan Demo', 'email' => 'customer@etoko.test']);

        User::factory()->count(9)->customer()->create();
    }

    private function createStaff(string $name, string $email, UserRole $role, ?Branch $branch = null): User
    {
        return User::factory()
            ->withRole($role)
            ->create([
                'name' => $name,
                'email' => $email,
                'branch_id' => $branch?->id,
            ]);
    }
}
