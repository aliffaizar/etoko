<?php

use App\Actions\Product\RefreshLowestPrice;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;

/*
 * Product promotions (P2-E8-T2, ADR-067). Runs against the real permission
 * baseline in config/rbac.php. Central only, like vouchers.
 */

function promotionPayload(array $overrides = []): array
{
    return [
        'name' => 'Akhir pekan',
        'type' => 'percentage',
        'value' => '20',
        'priority' => '0',
        'starts_at' => '',
        'ends_at' => '',
        'is_active' => '1',
        'product_ids' => [],
        'branch_ids' => [],
        ...$overrides,
    ];
}

it('redirects guests to the login page', function () {
    $this->get(route('admin.promotions.index'))->assertRedirect(route('login'));
});

it('lists promotions with their state for central staff', function () {
    Promotion::factory()->create(['name' => 'Berjalan']);
    Promotion::factory()->inactive()->create(['name' => 'Mati']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.promotions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/promotions/index')
            ->has('promotions.data', 2)
            ->where('can.create', true));
});

it('refuses a branch admin, even one given the permission', function () {
    $actor = User::factory()->adminCabang()->create();
    $actor->givePermissionTo('promotions.manage');

    $this->actingAs($actor)->get(route('admin.promotions.index'))->assertForbidden();
    $this->actingAs($actor)->post(route('admin.promotions.store'), promotionPayload([
        'product_ids' => [Product::factory()->create()->getKey()],
    ]))->assertForbidden();

    expect(Promotion::query()->count())->toBe(0);
});

it('creates a promotion and prices its products straight away', function () {
    $product = Product::factory()->withDefaultVariant()->create(['base_price' => 100_000]);
    $branch = Branch::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.promotions.store'), promotionPayload([
            'product_ids' => [$product->getKey()],
            'branch_ids' => [$branch->getKey()],
        ]))
        ->assertSessionHasNoErrors();

    $promotion = Promotion::query()->sole();

    expect($promotion->products->pluck('id')->all())->toBe([$product->getKey()])
        ->and($promotion->branches->pluck('id')->all())->toBe([$branch->getKey()])
        ->and($product->fresh()->lowest_price)->toBe(100_000);
});

it('refuses a percentage above one hundred and a period that ends before it starts', function () {
    $product = Product::factory()->create();
    $actor = User::factory()->adminPusat()->create();

    $this->actingAs($actor)
        ->post(route('admin.promotions.store'), promotionPayload([
            'value' => '120',
            'product_ids' => [$product->getKey()],
        ]))
        ->assertSessionHasErrors('value');

    $this->actingAs($actor)
        ->post(route('admin.promotions.store'), promotionPayload([
            'starts_at' => '2026-11-20T10:00',
            'ends_at' => '2026-11-19T10:00',
            'product_ids' => [$product->getKey()],
        ]))
        ->assertSessionHasErrors('ends_at');

    expect(Promotion::query()->count())->toBe(0);
});

it('requires at least one product', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.promotions.store'), promotionPayload(['product_ids' => []]))
        ->assertSessionHasErrors('product_ids');
});

it('drops a product from the promotion and returns it to its ordinary price', function () {
    $first = Product::factory()->withDefaultVariant()->create(['base_price' => 100_000]);
    $second = Product::factory()->withDefaultVariant()->create(['base_price' => 100_000]);
    $promotion = Promotion::factory()->percentage(50)->create();
    $promotion->products()->attach([$first->getKey(), $second->getKey()]);
    app(RefreshLowestPrice::class)->handle([$first->getKey(), $second->getKey()]);

    $actor = User::factory()->adminPusat()->create();

    $this->actingAs($actor)
        ->put(route('admin.promotions.update', $promotion), promotionPayload([
            'value' => '50',
            'product_ids' => [$first->getKey()],
        ]))
        ->assertSessionHasNoErrors();

    expect($first->fresh()->lowest_price)->toBe(50_000)
        ->and($second->fresh()->lowest_price)->toBe(100_000);
});

it('switches a promotion off, and refreshes the price it was holding down', function () {
    $product = Product::factory()->withDefaultVariant()->create(['base_price' => 100_000]);
    $promotion = Promotion::factory()->percentage(30)->create();
    $promotion->products()->attach($product->getKey());
    app(RefreshLowestPrice::class)->handle($product->getKey());

    expect($product->fresh()->lowest_price)->toBe(70_000);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->patch(route('admin.promotions.status.update', $promotion), ['is_active' => false])
        ->assertRedirect();

    expect($promotion->fresh()->is_active)->toBeFalse()
        ->and($product->fresh()->lowest_price)->toBe(100_000);
});

it('shows the form with the promotion\'s products and branches', function () {
    $product = Product::factory()->create(['name' => 'Sepatu Lari']);
    $branch = Branch::factory()->create();
    $promotion = Promotion::factory()->create();
    $promotion->products()->attach($product->getKey());
    $promotion->branches()->attach($branch->getKey());

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.promotions.edit', $promotion))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/promotions/edit')
            ->where('promotion.product_ids', [$product->getKey()])
            ->where('promotion.branch_ids', [$branch->getKey()]));
});

it('refuses a percentage that would make the products free', function () {
    $product = Product::factory()->withDefaultVariant()->create(['base_price' => 100_000]);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.promotions.store'), promotionPayload([
            'value' => '100',
            'product_ids' => [$product->getKey()],
        ]))
        ->assertSessionHasErrors('value');

    expect(Promotion::query()->count())->toBe(0);
});

it('refuses a fixed cut as large as the cheapest product it names', function () {
    $cheap = Product::factory()->withDefaultVariant()->create(['base_price' => 30_000]);
    $dear = Product::factory()->withDefaultVariant()->create(['base_price' => 200_000]);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.promotions.store'), promotionPayload([
            'type' => 'fixed',
            'value' => '30000',
            'product_ids' => [$cheap->getKey(), $dear->getKey()],
        ]))
        ->assertSessionHasErrors('value');

    expect(Promotion::query()->count())->toBe(0);
});
