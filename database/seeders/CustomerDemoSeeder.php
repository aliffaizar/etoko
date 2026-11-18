<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\Product;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Database\Seeder;

/**
 * A crowd of customers for the demo, so the customer list paginates, the
 * order history has more than four names in it, and the customer report has
 * somebody to rank.
 *
 * Indonesian names and addresses near the three demo branches, each customer
 * with one or two addresses and a few products on their wishlist. The demo
 * customer from `DemoDataSeeder` gets a wishlist too, so it is not empty on
 * first login.
 *
 * Skipped once the crowd is there, so re-running adds nobody. Never run it
 * against a production database.
 */
class CustomerDemoSeeder extends Seeder
{
    /** How many extra customers to create. */
    private const CUSTOMERS = 30;

    /** Marks the customers this seeder created, which is how a re-run knows. */
    private const EMAIL_DOMAIN = 'pelanggan.etoko.test';

    /**
     * Cities near the demo branches: [province, city, district, postal code].
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    private const AREAS = [
        ['DKI Jakarta', 'Jakarta Selatan', 'Kebayoran Baru', '12110'],
        ['DKI Jakarta', 'Jakarta Barat', 'Grogol Petamburan', '11450'],
        ['DKI Jakarta', 'Jakarta Timur', 'Duren Sawit', '13440'],
        ['Jawa Barat', 'Bandung', 'Coblong', '40132'],
        ['Jawa Barat', 'Bandung', 'Sukajadi', '40162'],
        ['Jawa Barat', 'Bekasi', 'Bekasi Selatan', '17141'],
        ['Jawa Timur', 'Surabaya', 'Gubeng', '60281'],
        ['Jawa Timur', 'Surabaya', 'Wonokromo', '60243'],
        ['Jawa Timur', 'Sidoarjo', 'Waru', '61256'],
    ];

    public function run(): void
    {
        if (User::query()->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->exists()) {
            return;
        }

        $faker = fake('id_ID');
        $products = Product::query()->published()->pluck('id');

        for ($index = 1; $index <= self::CUSTOMERS; $index++) {
            $name = $faker->name();

            $customer = User::factory()->customer()->create([
                'name' => $name,
                'email' => "pelanggan{$index}@".self::EMAIL_DOMAIN,
                'phone' => '08'.$faker->numerify('##########'),
            ]);

            $this->addAddresses($customer, $name, $faker->numberBetween(1, 2));
            $this->addWishlist($customer, $products->shuffle()->take($faker->numberBetween(0, 5))->all());
        }

        $demoCustomer = User::query()->where('email', 'customer@etoko.test')->first();

        if ($demoCustomer instanceof User && ! $demoCustomer->wishlists()->exists()) {
            $this->addWishlist($demoCustomer, $products->shuffle()->take(4)->all());
        }
    }

    private function addAddresses(User $customer, string $name, int $count): void
    {
        $faker = fake('id_ID');

        foreach (range(1, $count) as $position) {
            [$province, $city, $district, $postalCode] = $faker->randomElement(self::AREAS);

            Address::factory()->for($customer)->create([
                'label' => $position === 1 ? 'Rumah' : 'Kantor',
                'recipient_name' => $name,
                'phone' => $customer->phone,
                'province_name' => $province,
                'city_name' => $city,
                'district_name' => $district,
                'postal_code' => $postalCode,
                'address_line' => $faker->streetAddress(),
                'is_default' => $position === 1,
            ]);
        }
    }

    /**
     * @param  array<int, int>  $productIds
     */
    private function addWishlist(User $customer, array $productIds): void
    {
        foreach ($productIds as $productId) {
            Wishlist::query()->firstOrCreate(['user_id' => $customer->getKey(), 'product_id' => $productId]);
        }
    }
}
