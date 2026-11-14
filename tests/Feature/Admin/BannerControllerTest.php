<?php

use App\Models\Banner;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Homepage banners (P2-E8-T1). Central only, like vouchers. The pictures live
 * on the `public` disk, and that disk is faked so no file is written for real.
 */

beforeEach(function () {
    Storage::fake('public');
});

function bannerPayload(array $overrides = []): array
{
    return [
        'title' => 'Diskon akhir pekan',
        'subtitle' => 'Berlaku Sabtu dan Minggu',
        'link_url' => 'https://example.com/promo',
        'position' => 'hero',
        'branch_id' => '',
        'sort_order' => '1',
        'starts_at' => '',
        'ends_at' => '',
        'is_active' => '1',
        'image' => UploadedFile::fake()->image('hero.jpg', 1600, 600),
        ...$overrides,
    ];
}

it('redirects guests to the login page', function () {
    $this->get(route('admin.banners.index'))->assertRedirect(route('login'));
});

it('refuses a branch admin, even one given the permission', function () {
    $actor = User::factory()->adminCabang()->create();
    $actor->givePermissionTo('banners.manage');

    $this->actingAs($actor)->get(route('admin.banners.index'))->assertForbidden();
    $this->actingAs($actor)->post(route('admin.banners.store'), bannerPayload())->assertForbidden();

    expect(Banner::query()->count())->toBe(0);
});

it('stores the picture on the public disk and the path on the row', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.banners.store'), bannerPayload())
        ->assertSessionHasNoErrors();

    $banner = Banner::query()->sole();

    expect($banner->title)->toBe('Diskon akhir pekan')
        ->and($banner->image_path)->toStartWith('banners/')
        ->and($banner->branch_id)->toBeNull();
    Storage::disk('public')->assertExists($banner->image_path);
});

it('requires a picture when creating', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.banners.store'), bannerPayload(['image' => null]))
        ->assertSessionHasErrors('image');

    expect(Banner::query()->count())->toBe(0);
});

it('refuses a picture that is not an image and a period that ends before it starts', function () {
    $actor = User::factory()->adminPusat()->create();

    $this->actingAs($actor)
        ->post(route('admin.banners.store'), bannerPayload(['image' => UploadedFile::fake()->create('x.pdf', 10)]))
        ->assertSessionHasErrors('image');

    $this->actingAs($actor)
        ->post(route('admin.banners.store'), bannerPayload([
            'starts_at' => '2026-11-20T10:00',
            'ends_at' => '2026-11-19T10:00',
        ]))
        ->assertSessionHasErrors('ends_at');
});

it('keeps the picture when editing without a new one, and replaces it when given one', function () {
    $actor = User::factory()->adminPusat()->create();
    $this->actingAs($actor)->post(route('admin.banners.store'), bannerPayload());
    $banner = Banner::query()->sole();
    $original = $banner->image_path;

    $this->actingAs($actor)
        ->post(route('admin.banners.update', $banner), bannerPayload(['title' => 'Judul baru', 'image' => null]))
        ->assertSessionHasNoErrors();

    expect($banner->fresh()->image_path)->toBe($original)
        ->and($banner->fresh()->title)->toBe('Judul baru');

    $this->actingAs($actor)
        ->post(route('admin.banners.update', $banner), bannerPayload([
            'image' => UploadedFile::fake()->image('baru.jpg', 1600, 600),
        ]))
        ->assertSessionHasNoErrors();

    expect($banner->fresh()->image_path)->not->toBe($original);
    Storage::disk('public')->assertMissing($original);
    Storage::disk('public')->assertExists($banner->fresh()->image_path);
});

it('removes the pictures with the banner', function () {
    $actor = User::factory()->adminPusat()->create();
    $this->actingAs($actor)->post(route('admin.banners.store'), bannerPayload());
    $banner = Banner::query()->sole();
    $path = $banner->image_path;

    $this->actingAs($actor)->delete(route('admin.banners.destroy', $banner))->assertRedirect();

    expect(Banner::query()->count())->toBe(0);
    Storage::disk('public')->assertMissing($path);
});

it('switches a banner off without deleting it', function () {
    $banner = Banner::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->patch(route('admin.banners.status.update', $banner), ['is_active' => false])
        ->assertRedirect();

    expect($banner->fresh()->is_active)->toBeFalse();
});

it('keeps a banner for another branch off the front page of this one', function () {
    $home = Branch::factory()->default()->create();
    $other = Branch::factory()->create();
    Banner::factory()->create(['title' => 'Semua cabang', 'sort_order' => 1]);
    Banner::factory()->forBranch($home)->create(['title' => 'Cabang ini', 'sort_order' => 2]);
    Banner::factory()->forBranch($other)->create(['title' => 'Cabang lain', 'sort_order' => 3]);
    Banner::factory()->inactive()->create(['title' => 'Nonaktif', 'sort_order' => 4]);
    Banner::factory()->between(now()->subDays(3), now()->subDay())->create(['title' => 'Sudah lewat', 'sort_order' => 5]);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('shop/home')
            ->has('banners', 2)
            ->where('banners.0.title', 'Semua cabang')
            ->where('banners.1.title', 'Cabang ini'));
});
