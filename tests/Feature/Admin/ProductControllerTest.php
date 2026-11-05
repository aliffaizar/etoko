<?php

use App\Enums\ProductPublicationState;
use App\Enums\ProductType;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;

/*
 * Product management (P1-E3-T5).
 *
 * These tests run against the real permission baseline from config/rbac.php, so
 * a change there that widens access shows up here.
 */

function productPayload(array $overrides = []): array
{
    return [
        'category_id' => Category::factory()->create()->id,
        'name' => 'Kipas Angin Berdiri 16 Inci',
        'type' => ProductType::Simple->value,
        'sku' => 'KPS-16-PUTIH',
        'barcode' => '8991102000123',
        'short_description' => 'Kipas hemat listrik dengan tiga kecepatan.',
        'description' => '<p>Detail produk.</p>',
        'base_price' => 250000,
        'compare_at_price' => 300000,
        'cost_price' => 180000,
        'weight_gram' => 3500,
        'length_cm' => 40,
        'width_cm' => 40,
        'height_cm' => 120,
        'is_active' => true,
        'is_featured' => false,
        'published_at' => '2026-11-01 08:00',
        'meta_title' => null,
        'meta_description' => null,
        ...$overrides,
    ];
}

it('redirects guests to the login page', function () {
    $this->get(route('admin.products.index'))->assertRedirect(route('login'));
});

it('lists products for every role holding products.view', function (UserRole $role) {
    Product::factory()->count(2)->withDefaultVariant()->create();

    $this->actingAs(User::factory()->withRole($role)->forBranch()->create())
        ->get(route('admin.products.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/products/index')
            ->has('products.data', 2)
            ->has('filters'));
})->with([
    'super admin' => [UserRole::SuperAdmin],
    'admin pusat' => [UserRole::AdminPusat],
    'admin cabang' => [UserRole::AdminCabang],
    'staff gudang' => [UserRole::StaffGudang],
    'kasir' => [UserRole::Kasir],
]);

it('only offers the create button to central staff', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.products.index'))
        ->assertInertia(fn ($page) => $page->where('can.create', true));

    $this->actingAs(User::factory()->adminCabang()->create())
        ->get(route('admin.products.index'))
        ->assertInertia(fn ($page) => $page->where('can.create', false));
});

it('forbids customers', function () {
    $this->actingAs(User::factory()->customer()->create())
        ->get(route('admin.products.index'))
        ->assertForbidden();
});

it('shows the default variant sku and the category on each row', function () {
    $category = Category::factory()->create(['name' => 'Elektronik']);
    $product = Product::factory()->for($category)->create();
    ProductVariant::factory()->for($product)->default()->create(['sku' => 'KPS-16']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.products.index'))
        ->assertInertia(fn ($page) => $page
            ->where('products.data.0.sku', 'KPS-16')
            ->where('products.data.0.category_name', 'Elektronik')
            ->where('products.data.0.variant_count', 1));
});

it('reports the publication state each product is in', function () {
    $actor = User::factory()->adminPusat()->create();

    Product::factory()->withDefaultVariant()->create(['name' => 'Terbit']);
    Product::factory()->draft()->withDefaultVariant()->create(['name' => 'Draf']);
    Product::factory()->inactive()->withDefaultVariant()->create(['name' => 'Nonaktif']);
    Product::factory()->publishedAt(now()->addWeek())->withDefaultVariant()->create(['name' => 'Terjadwal']);

    foreach (['published', 'draft', 'inactive', 'scheduled'] as $state) {
        $this->actingAs($actor)
            ->get(route('admin.products.index', ['state' => $state]))
            ->assertInertia(fn ($page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.state', $state));
    }
});

it('filters by category including its subcategories', function () {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();
    $other = Category::factory()->create();

    Product::factory()->for($root)->withDefaultVariant()->create();
    Product::factory()->for($child)->withDefaultVariant()->create();
    Product::factory()->for($other)->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.products.index', ['category' => $root->id]))
        ->assertInertia(fn ($page) => $page->has('products.data', 2));
});

it('filters by type and by the featured flag', function () {
    $actor = User::factory()->adminPusat()->create();

    Product::factory()->withDefaultVariant()->create();
    Product::factory()->variable()->featured()->withDefaultVariant()->create(['name' => 'Kaos Polos']);

    $this->actingAs($actor)
        ->get(route('admin.products.index', ['type' => ProductType::Variable->value]))
        ->assertInertia(fn ($page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Kaos Polos'));

    $this->actingAs($actor)
        ->get(route('admin.products.index', ['featured' => 'yes']))
        ->assertInertia(fn ($page) => $page->has('products.data', 1));

    $this->actingAs($actor)
        ->get(route('admin.products.index', ['featured' => 'no']))
        ->assertInertia(fn ($page) => $page->has('products.data', 1));
});

it('searches by name, slug, sku and barcode', function () {
    $actor = User::factory()->adminPusat()->create();

    $product = Product::factory()->create(['name' => 'Kipas Angin', 'slug' => 'kipas-angin']);
    ProductVariant::factory()->for($product)->default()->create([
        'sku' => 'KPS-16-PUTIH',
        'barcode' => '8991102000123',
    ]);
    Product::factory()->withDefaultVariant()->create(['name' => 'Setrika Uap', 'slug' => 'setrika-uap']);

    foreach (['Kipas', 'kipas-angin', 'KPS-16', '899110200'] as $term) {
        $this->actingAs($actor)
            ->get(route('admin.products.index', ['search' => $term]))
            ->assertInertia(fn ($page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.id', $product->id));
    }
});

it('rejects a sort column that is not whitelisted', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.products.index', ['sort' => 'cost_price']))
        ->assertSessionHasErrors('sort');
});

it('paginates the listing at fifteen rows', function () {
    Product::factory()->count(16)->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.products.index'))
        ->assertInertia(fn ($page) => $page
            ->has('products.data', 15)
            ->where('products.total', 16));
});

it('creates a product with its default variant and derives the slug', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.store'), productPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.products.edit', Product::firstOrFail()));

    $product = Product::with('variants')->firstOrFail();

    expect($product->slug)->toBe('kipas-angin-berdiri-16-inci')
        ->and($product->base_price)->toBe(250000)
        ->and($product->weight_gram)->toBe(3500)
        ->and($product->variants)->toHaveCount(1);

    $variant = $product->variants->first();

    expect($variant->sku)->toBe('KPS-16-PUTIH')
        ->and($variant->barcode)->toBe('8991102000123')
        ->and($variant->is_default)->toBeTrue()
        // Left null on purpose, so the variant keeps following the product.
        ->and($variant->price)->toBeNull()
        ->and($variant->weight_gram)->toBeNull();
});

it('upper cases a submitted sku', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.store'), productPayload(['sku' => 'kps-16-putih']));

    expect(ProductVariant::firstOrFail()->sku)->toBe('KPS-16-PUTIH');
});

it('gives a second product with the same name a distinct slug', function () {
    $actor = User::factory()->adminPusat()->create();

    $this->actingAs($actor)->post(route('admin.products.store'), productPayload(['sku' => 'SKU-1', 'barcode' => null]));
    $this->actingAs($actor)->post(route('admin.products.store'), productPayload(['sku' => 'SKU-2', 'barcode' => null]));

    expect(Product::pluck('slug')->all())
        ->toBe(['kipas-angin-berdiri-16-inci', 'kipas-angin-berdiri-16-inci-2']);
});

it('refuses a sku another variant already holds', function () {
    ProductVariant::factory()->create(['sku' => 'KPS-16-PUTIH']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.store'), productPayload())
        ->assertSessionHasErrors('sku');

    expect(Product::count())->toBe(1);
});

it('refuses a barcode another variant already holds', function () {
    ProductVariant::factory()->create(['barcode' => '8991102000123']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.store'), productPayload())
        ->assertSessionHasErrors('barcode');
});

it('refuses a sku with characters that do not belong in one', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.store'), productPayload(['sku' => 'KPS 16/PUTIH']))
        ->assertSessionHasErrors('sku');
});

it('refuses a compare-at price that is not above the selling price', function (int $compareAt) {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.store'), productPayload([
            'base_price' => 250000,
            'compare_at_price' => $compareAt,
        ]))
        ->assertSessionHasErrors('compare_at_price');
})->with([
    'equal' => [250000],
    'lower' => [200000],
]);

it('accepts a product without a compare-at price', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.store'), productPayload(['compare_at_price' => null]))
        ->assertSessionHasNoErrors();

    expect(Product::firstOrFail()->compare_at_price)->toBeNull();
});

it('refuses a category that does not exist', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.store'), productPayload(['category_id' => 9999]))
        ->assertSessionHasErrors('category_id');
});

it('normalises an emptied price and weight to zero rather than refusing them', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.store'), productPayload([
            'base_price' => '',
            'weight_gram' => '',
            'compare_at_price' => null,
        ]))
        ->assertSessionHasNoErrors();

    $product = Product::firstOrFail();

    expect($product->base_price)->toBe(0)->and($product->weight_gram)->toBe(0);
});

it('forbids a branch admin from creating a product', function (UserRole $role) {
    $this->actingAs(User::factory()->withRole($role)->forBranch()->create())
        ->post(route('admin.products.store'), productPayload())
        ->assertForbidden();

    expect(Product::count())->toBe(0);
})->with([
    'admin cabang' => [UserRole::AdminCabang],
    'staff gudang' => [UserRole::StaffGudang],
    'kasir' => [UserRole::Kasir],
]);

it('shows the edit screen with the default variant sku', function () {
    $product = Product::factory()->create();
    ProductVariant::factory()->for($product)->default()->create(['sku' => 'KPS-16']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.products.edit', $product))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/products/edit')
            ->where('product.id', $product->id)
            ->where('product.sku', 'KPS-16')
            ->has('options.categories')
            ->where('can.update', true));
});

it('formats the publish date for a datetime-local input', function () {
    $product = Product::factory()->withDefaultVariant()->publishedAt(
        now()->setDate(2026, 11, 1)->setTime(8, 30),
    )->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.products.edit', $product))
        ->assertInertia(fn ($page) => $page->where('product.published_at', '2026-11-01T08:30'));
});

it('updates a product and its default variant identity', function () {
    $product = Product::factory()->create(['name' => 'Lama', 'slug' => 'lama']);
    $variant = ProductVariant::factory()->for($product)->default()->create(['sku' => 'OLD-SKU']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.products.update', $product), productPayload([
            'slug' => 'kipas-angin-berdiri',
            'category_id' => $product->category_id,
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.products.edit', $product));

    expect($product->refresh()->name)->toBe('Kipas Angin Berdiri 16 Inci')
        ->and($product->slug)->toBe('kipas-angin-berdiri')
        ->and($variant->refresh()->sku)->toBe('KPS-16-PUTIH');
});

it('lets a product keep its own slug and sku', function () {
    $product = Product::factory()->create(['slug' => 'kipas-angin']);
    ProductVariant::factory()->for($product)->default()->create(['sku' => 'KPS-16-PUTIH']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.products.update', $product), productPayload([
            'slug' => 'kipas-angin',
            'category_id' => $product->category_id,
        ]))
        ->assertSessionHasNoErrors();
});

it('refuses a slug another product already holds', function () {
    Product::factory()->create(['slug' => 'kipas-angin']);
    $product = Product::factory()->withDefaultVariant()->create(['slug' => 'setrika-uap']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.products.update', $product), productPayload([
            'slug' => 'kipas-angin',
            'sku' => $product->variants()->value('sku'),
            'barcode' => null,
        ]))
        ->assertSessionHasErrors('slug');
});

it('refuses a sku that belongs to another product variant', function () {
    ProductVariant::factory()->create(['sku' => 'TAKEN-SKU']);
    $product = Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.products.update', $product), productPayload([
            'slug' => $product->slug,
            'sku' => 'TAKEN-SKU',
            'barcode' => null,
        ]))
        ->assertSessionHasErrors('sku');
});

it('forbids a branch admin from updating a product', function () {
    $product = Product::factory()->withDefaultVariant()->create(['name' => 'Lama']);

    $this->actingAs(User::factory()->adminCabang()->create())
        ->put(route('admin.products.update', $product), productPayload(['slug' => 'baru']))
        ->assertForbidden();

    expect($product->refresh()->name)->toBe('Lama');
});

it('toggles the active flag from the listing', function () {
    $product = Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->patch(route('admin.products.status.update', $product), ['is_active' => false])
        ->assertRedirect();

    expect($product->refresh()->is_active)->toBeFalse();
});

it('toggles the featured flag without touching the active one', function () {
    $product = Product::factory()->withDefaultVariant()->create(['is_active' => true]);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->patch(route('admin.products.status.update', $product), ['is_featured' => true])
        ->assertSessionHasNoErrors();

    expect($product->refresh()->is_featured)->toBeTrue()
        ->and($product->is_active)->toBeTrue();
});

it('refuses a status request that carries no action at all', function () {
    $product = Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->patch(route('admin.products.status.update', $product), [])
        ->assertSessionHasErrors(['is_active', 'is_featured', 'publication']);
});

/*
 * Publishing (BUG: a product could only be published by typing a date into an
 * optional field, so every product created from the panel stayed a draft).
 */

it('publishes a draft product in one action', function () {
    $product = Product::factory()->draft()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->patch(route('admin.products.status.update', $product), ['publication' => 'published'])
        ->assertRedirect();

    expect($product->refresh()->publicationState())->toBe(ProductPublicationState::Published)
        ->and($product->isPublished())->toBeTrue();
});

it('switches a product on when publishing it, since otherwise it stays invisible', function () {
    $product = Product::factory()->inactive()->draft()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->patch(route('admin.products.status.update', $product), ['publication' => 'published']);

    expect($product->refresh()->is_active)->toBeTrue()
        ->and($product->isPublished())->toBeTrue();
});

it('brings a published product back to draft', function () {
    $product = Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->patch(route('admin.products.status.update', $product), ['publication' => 'draft']);

    expect($product->refresh()->published_at)->toBeNull()
        ->and($product->publicationState())->toBe(ProductPublicationState::Draft)
        // Returning to draft hides the product; it does not switch it off.
        ->and($product->is_active)->toBeTrue();
});

it('dates a publish from the server clock, not the request', function () {
    $product = Product::factory()->draft()->withDefaultVariant()->create();

    $this->freezeTime(function () use ($product) {
        $this->actingAs(User::factory()->adminPusat()->create())
            ->patch(route('admin.products.status.update', $product), [
                'publication' => 'published',
                'published_at' => '2030-01-01 00:00:00',
            ]);

        expect($product->refresh()->published_at->toIso8601String())->toBe(now()->toIso8601String());
    });
});

it('refuses a publication value it does not know', function () {
    $product = Product::factory()->draft()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->patch(route('admin.products.status.update', $product), ['publication' => 'nanti'])
        ->assertSessionHasErrors('publication');

    expect($product->refresh()->published_at)->toBeNull();
});

it('publishes straight from the create form', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.store'), productPayload([
            'publication_mode' => 'published',
            'published_at' => null,
        ]))
        ->assertRedirect();

    expect(Product::query()->sole()->isPublished())->toBeTrue();
});

it('keeps a new product a draft when the form says draft', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.store'), productPayload([
            'publication_mode' => 'draft',
            // A date left in the payload must not sneak the product live.
            'published_at' => '2026-11-01 08:00',
        ]));

    $product = Product::query()->sole();

    expect($product->published_at)->toBeNull()
        ->and($product->publicationState())->toBe(ProductPublicationState::Draft);
});

it('schedules a new product for the chosen moment', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.store'), productPayload([
            'publication_mode' => 'scheduled',
            'published_at' => now()->addWeek()->format('Y-m-d H:i'),
        ]));

    expect(Product::query()->sole()->publicationState())->toBe(ProductPublicationState::Scheduled);
});

it('dates a publish from the create form on the server clock', function () {
    $this->freezeTime(function () {
        $this->actingAs(User::factory()->adminPusat()->create())
            ->post(route('admin.products.store'), productPayload([
                'publication_mode' => 'published',
                // A browser clock a decade ahead must not win.
                'published_at' => '2036-01-01 00:00',
            ]));

        expect(Product::query()->sole()->published_at->toIso8601String())
            ->toBe(now()->toIso8601String());
    });
});

it('keeps the original release moment when a published product is edited again', function () {
    $releasedAt = now()->subMonth()->startOfMinute();
    $product = Product::factory()->publishedAt($releasedAt)->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.products.update', $product), productPayload([
            'slug' => $product->slug,
            'sku' => $product->variants()->value('sku'),
            'barcode' => null,
            'name' => 'Nama Yang Disunting',
            'publication_mode' => 'published',
        ]));

    expect($product->refresh()->published_at->toIso8601String())->toBe($releasedAt->toIso8601String())
        ->and($product->name)->toBe('Nama Yang Disunting');
});

it('reports which publication choice the edit form should start on', function (callable $build, string $expected) {
    /** @var Product $product */
    $product = $build()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.products.edit', $product))
        ->assertInertia(fn ($page) => $page->where('product.publication_mode', $expected));
})->with([
    'draft' => [fn () => Product::factory()->draft(), 'draft'],
    'published' => [fn () => Product::factory(), 'published'],
    'scheduled' => [fn () => Product::factory()->publishedAt(now()->addWeek()), 'scheduled'],
]);

it('refuses a publication mode it does not know', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.store'), productPayload(['publication_mode' => 'nanti-saja']))
        ->assertSessionHasErrors('publication_mode');

    expect(Product::count())->toBe(0);
});

it('still honours a published_at sent without a publication mode', function () {
    // The CSV import and any other caller write the date directly; adding the
    // form's helper must not have broken them.
    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.products.store'), productPayload(['published_at' => '2026-11-01 08:00']));

    expect(Product::query()->sole()->published_at->format('Y-m-d H:i'))->toBe('2026-11-01 08:00');
});

it('forbids a branch admin from publishing', function () {
    $product = Product::factory()->draft()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminCabang()->create())
        ->patch(route('admin.products.status.update', $product), ['publication' => 'published'])
        ->assertForbidden();

    expect($product->refresh()->published_at)->toBeNull();
});

it('forbids a cashier from toggling a product', function () {
    $product = Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->kasir()->create())
        ->patch(route('admin.products.status.update', $product), ['is_active' => false])
        ->assertForbidden();

    expect($product->refresh()->is_active)->toBeTrue();
});

it('soft deletes a product and keeps its variants', function () {
    $product = Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.products.destroy', $product))
        ->assertRedirect(route('admin.products.index'));

    expect($product->refresh()->trashed())->toBeTrue()
        ->and(ProductVariant::where('product_id', $product->id)->count())->toBe(1);
});

it('forbids a branch admin from deleting a product', function () {
    $product = Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminCabang()->create())
        ->delete(route('admin.products.destroy', $product))
        ->assertForbidden();

    expect($product->refresh()->trashed())->toBeFalse();
});

it('records product changes in the audit log', function () {
    $product = Product::factory()->withDefaultVariant()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->patch(route('admin.products.status.update', $product), ['is_featured' => true]);

    expect($product->auditLogs()->where('event', 'updated')->exists())->toBeTrue();
});
