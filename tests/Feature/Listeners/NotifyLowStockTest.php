<?php

use App\Actions\Inventory\AdjustStock;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\LowStockNotification;
use Illuminate\Support\Facades\Notification;

/*
 * Low-stock alerts (P1-E4-T9).
 */

function lowStockVariant(): ProductVariant
{
    return Product::factory()->withDefaultVariant()->create()->variants()->sole();
}

/**
 * Drop a variant's stock through its minimum, the way the panel would.
 */
function crossTheMinimum(Warehouse $warehouse, ProductVariant $variant): void
{
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)
        ->quantity(6)->minimum(5)->create();

    app(AdjustStock::class)->handle($warehouse, $variant, -2, 'Dua unit rusak');
}

it('notifies the branch staff who can act on it', function () {
    Notification::fake();

    $branch = Branch::factory()->create();
    $warehouse = Warehouse::factory()->default()->for($branch)->create();
    $admin = User::factory()->adminCabang($branch)->create();
    $warehouseStaff = User::factory()->staffGudang($branch)->create();

    crossTheMinimum($warehouse, lowStockVariant());

    Notification::assertSentTo([$admin, $warehouseStaff], LowStockNotification::class);
});

it('leaves out a cashier, who sells stock rather than reordering it', function () {
    Notification::fake();

    $branch = Branch::factory()->create();
    $warehouse = Warehouse::factory()->default()->for($branch)->create();
    $cashier = User::factory()->kasir($branch)->create();

    crossTheMinimum($warehouse, lowStockVariant());

    Notification::assertNotSentTo($cashier, LowStockNotification::class);
});

it('leaves out central staff, who would receive every branch alert', function () {
    Notification::fake();

    $branch = Branch::factory()->create();
    $warehouse = Warehouse::factory()->default()->for($branch)->create();
    $central = User::factory()->adminPusat()->create();

    crossTheMinimum($warehouse, lowStockVariant());

    Notification::assertNotSentTo($central, LowStockNotification::class);
});

it('leaves out staff of another branch', function () {
    Notification::fake();

    $branch = Branch::factory()->create();
    $warehouse = Warehouse::factory()->default()->for($branch)->create();
    $elsewhere = User::factory()->staffGudang(Branch::factory()->create())->create();

    crossTheMinimum($warehouse, lowStockVariant());

    Notification::assertNotSentTo($elsewhere, LowStockNotification::class);
});

it('leaves out a deactivated account, which cannot sign in to read it', function () {
    Notification::fake();

    $branch = Branch::factory()->create();
    $warehouse = Warehouse::factory()->default()->for($branch)->create();
    $inactive = User::factory()->staffGudang($branch)->create(['is_active' => false]);

    crossTheMinimum($warehouse, lowStockVariant());

    Notification::assertNotSentTo($inactive, LowStockNotification::class);
});

it('stores the figures from the moment it fired, not the current ones', function () {
    $branch = Branch::factory()->create();
    $warehouse = Warehouse::factory()->default()->for($branch)->create();
    $staff = User::factory()->staffGudang($branch)->create();
    $variant = lowStockVariant();

    crossTheMinimum($warehouse, $variant);

    // Stock moves again after the alert was sent.
    app(AdjustStock::class)->handle($warehouse, $variant, -1, 'Satu lagi rusak');

    $notification = $staff->notifications()->sole();

    expect($notification->data['quantity'])->toBe(4)
        ->and($notification->data['min_quantity'])->toBe(5)
        ->and($notification->data['sku'])->toBe($variant->sku)
        ->and($notification->data['warehouse_name'])->toBe($warehouse->name)
        ->and($notification->data['branch_id'])->toBe($branch->id)
        ->and($notification->data['message'])->toContain('tinggal 4 unit');
});

it('sends one alert per crossing, not one per movement that leaves it low', function () {
    $branch = Branch::factory()->create();
    $warehouse = Warehouse::factory()->default()->for($branch)->create();
    $staff = User::factory()->staffGudang($branch)->create();
    $variant = lowStockVariant();

    crossTheMinimum($warehouse, $variant);
    app(AdjustStock::class)->handle($warehouse, $variant, -1, 'Satu lagi');
    app(AdjustStock::class)->handle($warehouse, $variant, -1, 'Dan satu lagi');

    expect($staff->notifications()->count())->toBe(1);
});

it('alerts again once stock has recovered above the minimum and fallen back', function () {
    $branch = Branch::factory()->create();
    $warehouse = Warehouse::factory()->default()->for($branch)->create();
    $staff = User::factory()->staffGudang($branch)->create();
    $variant = lowStockVariant();

    crossTheMinimum($warehouse, $variant);
    app(AdjustStock::class)->handle($warehouse, $variant, 20, 'Kiriman baru');
    app(AdjustStock::class)->handle($warehouse, $variant, -20, 'Terjual habis');

    expect($staff->notifications()->count())->toBe(2);
});

it('says nothing when nobody set a minimum', function () {
    Notification::fake();

    $branch = Branch::factory()->create();
    $warehouse = Warehouse::factory()->default()->for($branch)->create();
    User::factory()->staffGudang($branch)->create();
    $variant = lowStockVariant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity(3)->create();

    app(AdjustStock::class)->handle($warehouse, $variant, -3, 'Habis terjual');

    Notification::assertNothingSent();
});

it('shows the branch dashboard what is low now and what it was told earlier', function () {
    $branch = Branch::factory()->create();
    $warehouse = Warehouse::factory()->default()->for($branch)->create();
    $staff = User::factory()->staffGudang($branch)->create();
    $variant = lowStockVariant();

    crossTheMinimum($warehouse, $variant);

    $this->actingAs($staff)
        ->get(route('branch.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.low_stock', 1)
            ->has('lowStock', 1)
            ->where('lowStock.0.quantity', 4)
            ->where('lowStock.0.min_quantity', 5)
            ->where('lowStock.0.shortfall', 1)
            ->has('notifications', 1)
            ->where('notifications.0.is_read', false));
});

it('sorts the dashboard panel by shortfall against the minimum, not by quantity', function () {
    $branch = Branch::factory()->create();
    $warehouse = Warehouse::factory()->default()->for($branch)->create();
    $staff = User::factory()->staffGudang($branch)->create();

    $nearlyFine = lowStockVariant();
    $badlyShort = lowStockVariant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($nearlyFine)->quantity(0)->minimum(1)->create();
    Stock::factory()->inWarehouse($warehouse)->forVariant($badlyShort)->quantity(10)->minimum(100)->create();

    $this->actingAs($staff)
        ->get(route('branch.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('lowStock.0.product_variant_id', $badlyShort->id)
            ->where('lowStock.1.product_variant_id', $nearlyFine->id));
});

it('marks the signed-in user own alerts as read', function () {
    $branch = Branch::factory()->create();
    $warehouse = Warehouse::factory()->default()->for($branch)->create();
    $mine = User::factory()->staffGudang($branch)->create();
    $theirs = User::factory()->adminCabang($branch)->create();

    crossTheMinimum($warehouse, lowStockVariant());

    $this->actingAs($mine)
        ->post(route('branch.notifications.read'))
        ->assertRedirect();

    expect($mine->unreadNotifications()->count())->toBe(0)
        // Somebody else's copy of the same alert is untouched.
        ->and($theirs->unreadNotifications()->count())->toBe(1);
});

it('notifies when a new minimum is set above what is on the shelf', function () {
    $branch = Branch::factory()->create();
    $warehouse = Warehouse::factory()->default()->for($branch)->create();
    $staff = User::factory()->staffGudang($branch)->create();
    $variant = lowStockVariant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity(2)->create();

    $this->actingAs($staff)
        ->put(route('branch.stocks.thresholds.update'), [
            'warehouse_id' => $warehouse->id,
            'variant_ids' => [$variant->id],
            'min_quantity' => 10,
        ])
        ->assertRedirect();

    expect($staff->notifications()->count())->toBe(1)
        ->and($staff->notifications()->sole()->data['min_quantity'])->toBe(10);
});

it('refuses a customer the notification endpoint', function () {
    $this->actingAs(User::factory()->withRole(UserRole::Customer)->create())
        ->post(route('branch.notifications.read'))
        ->assertForbidden();
});
