<?php

use App\Enums\VoucherAppliesTo;
use App\Enums\VoucherType;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherUsage;

/*
 * Voucher management (P2-E5-T2). Runs against the real permission baseline
 * in config/rbac.php.
 */

function voucherPayload(array $overrides = []): array
{
    return [
        'code' => 'hemat10',
        'name' => 'Hemat 10%',
        'description' => '',
        'type' => 'percentage',
        'value' => '10',
        'max_discount' => '50000',
        'min_order_amount' => '100000',
        'quota' => '',
        'per_user_limit' => '1',
        'applies_to' => 'all',
        'channel' => 'all',
        'starts_at' => '',
        'ends_at' => '',
        'is_active' => '1',
        ...$overrides,
    ];
}

it('redirects guests to the login page', function () {
    $this->get(route('admin.vouchers.index'))->assertRedirect(route('login'));
});

it('lists vouchers with their state for central staff', function () {
    Voucher::factory()->create(['code' => 'JALAN']);
    Voucher::factory()->ended()->create(['code' => 'LEWAT']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.vouchers.index', ['state' => 'ended']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/vouchers/index')
            ->has('vouchers.data', 1)
            ->where('vouchers.data.0.code', 'LEWAT')
            ->where('vouchers.data.0.state.label', 'Berakhir')
            ->where('can.create', true));
});

it('refuses a branch admin, even one given the permission', function () {
    $actor = User::factory()->adminCabang()->create();

    $this->actingAs($actor)->get(route('admin.vouchers.index'))->assertForbidden();

    $actor->givePermissionTo('vouchers.manage');

    $this->actingAs($actor)->post(route('admin.vouchers.store'), voucherPayload())->assertForbidden();
    expect(Voucher::query()->count())->toBe(0);
});

it('creates a voucher with its code upper-cased and its branches', function () {
    $actor = User::factory()->adminPusat()->create();
    $branch = Branch::factory()->create();

    $this->actingAs($actor)
        ->post(route('admin.vouchers.store'), voucherPayload(['branch_ids' => [$branch->getKey()]]))
        ->assertSessionHasNoErrors();

    $voucher = Voucher::query()->sole();

    expect($voucher->code)->toBe('HEMAT10')
        ->and($voucher->type)->toBe(VoucherType::Percentage)
        ->and($voucher->max_discount)->toBe(50_000)
        ->and($voucher->quota)->toBeNull()
        ->and($voucher->created_by)->toBe($actor->getKey())
        ->and($voucher->branches->modelKeys())->toBe([$branch->getKey()])
        ->and(AuditLog::query()->where('auditable_type', $voucher->getMorphClass())->count())->toBe(1);
});

it('refuses a code already taken, whatever its capitalisation', function () {
    Voucher::factory()->create(['code' => 'HEMAT10']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.vouchers.store'), voucherPayload(['code' => 'Hemat10']))
        ->assertSessionHasErrors(['code' => 'kode voucher sudah digunakan.']);
});

it('refuses values that do not fit the type', function (array $overrides, string $field, string $message) {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.vouchers.store'), voucherPayload($overrides))
        ->assertSessionHasErrors([$field => $message]);
})->with([
    'percentage above 100' => [['value' => '150'], 'value', 'Persentase diskon harus antara 1 dan 100.'],
    'fixed with no amount' => [['type' => 'fixed', 'value' => '0'], 'value', 'Isi besar potongan dalam rupiah.'],
    'category with none picked' => [['applies_to' => 'category'], 'category_ids', 'Pilih minimal satu kategori.'],
    'product with none picked' => [['applies_to' => 'product'], 'product_ids', 'Pilih minimal satu produk.'],
    'end before start' => [['starts_at' => '2026-12-10T10:00', 'ends_at' => '2026-12-01T10:00'], 'ends_at', 'Waktu berakhir harus setelah waktu mulai berlaku.'],
    'code with spaces' => [['code' => 'HEMAT 10'], 'code', 'Kode voucher hanya boleh berisi huruf, angka, tanda hubung, dan garis bawah.'],
]);

it('keeps only the list the voucher is narrowed to', function () {
    $category = Category::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.vouchers.store'), voucherPayload([
            'type' => 'free_shipping',
            'value' => '5000',
            'applies_to' => 'category',
            'category_ids' => [$category->getKey()],
        ]))
        ->assertSessionHasNoErrors();

    $voucher = Voucher::query()->sole();

    expect($voucher->applies_to)->toBe(VoucherAppliesTo::Category)
        ->and($voucher->value)->toBe(0)
        ->and($voucher->categories->modelKeys())->toBe([$category->getKey()])
        ->and($voucher->products)->toHaveCount(0);
});

it('updates a voucher but never lowers its quota below what was used', function () {
    $voucher = Voucher::factory()->quota(10, used: 4)->create(['code' => 'LAMA']);
    $actor = User::factory()->adminPusat()->create();

    $this->actingAs($actor)
        ->put(route('admin.vouchers.update', $voucher), voucherPayload(['code' => 'LAMA', 'quota' => '3']))
        ->assertSessionHasErrors(['quota' => 'Kuota tidak boleh lebih kecil dari yang sudah terpakai (4).']);

    $this->actingAs($actor)
        ->put(route('admin.vouchers.update', $voucher), voucherPayload(['code' => 'LAMA', 'name' => 'Baru', 'quota' => '4']))
        ->assertRedirect(route('admin.vouchers.edit', $voucher));

    expect($voucher->fresh())
        ->name->toBe('Baru')
        ->quota->toBe(4)
        ->used_count->toBe(4);
});

it('switches a voucher off from the listing', function () {
    $voucher = Voucher::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->patch(route('admin.vouchers.status.update', $voucher), ['is_active' => false])
        ->assertRedirect();

    expect($voucher->fresh()->is_active)->toBeFalse();
});

it('deletes an unused voucher', function () {
    $voucher = Voucher::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.vouchers.destroy', $voucher))
        ->assertRedirect(route('admin.vouchers.index'));

    expect(Voucher::query()->count())->toBe(0);
});

it('refuses to delete a voucher that has been redeemed, and says why on the listing', function () {
    $voucher = Voucher::factory()->create();
    VoucherUsage::factory()->for($voucher)->create();
    $actor = User::factory()->adminPusat()->create();

    $this->actingAs($actor)
        ->get(route('admin.vouchers.index'))
        ->assertInertia(fn ($page) => $page
            ->where('vouchers.data.0.can.delete', false)
            ->where('vouchers.data.0.delete_blocked_reason', 'Voucher ini sudah dipakai di 1 pesanan. Nonaktifkan saja agar riwayat pesanan tetap utuh.'));

    $this->actingAs($actor)->delete(route('admin.vouchers.destroy', $voucher))->assertForbidden();

    expect($voucher->fresh())->not->toBeNull();
});

it('shows the edit form with the latest redemptions', function () {
    $voucher = Voucher::factory()->create();
    VoucherUsage::factory()->for($voucher)->create(['discount_amount' => 12_000]);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.vouchers.edit', $voucher))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/vouchers/edit')
            ->where('voucher.code', $voucher->code)
            ->has('usages', 1)
            ->where('usages.0.discount_amount', 12_000)
            ->has('options.products')
            ->has('options.categories'));
});
