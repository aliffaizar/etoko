<?php

use App\Models\Branch;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;

/*
 * Which end of a stock transfer may take which step (P2-E3, docs/04-rbac.md).
 *
 * The 404 from `StockTransferBranchScope` keeps a third branch out of the
 * route; these refusals are the second line, and the one that tells the two
 * ends apart.
 */

beforeEach(function () {
    $this->origin = Branch::factory()->create();
    $this->destination = Branch::factory()->create();
    $this->outsider = Branch::factory()->create();

    $from = Warehouse::factory()->default()->for($this->origin)->create();
    $to = Warehouse::factory()->default()->for($this->destination)->create();
    $between = fn () => StockTransfer::factory()->between($from, $to);

    $this->requested = $between()->create();
    $this->approved = $between()->approved()->create();
    $this->inTransit = $between()->inTransit()->create();
    $this->rejected = $between()->rejected()->create();
});

/**
 * @return array<string, bool>
 */
function transferAbilities(User $user, StockTransfer $transfer): array
{
    return collect(['view', 'approve', 'reject', 'ship', 'receive', 'cancel'])
        ->mapWithKeys(fn (string $ability): array => [$ability => $user->can($ability, $transfer)])
        ->all();
}

it('lets the origin admin decide and ship, but not receive or cancel', function () {
    $admin = User::factory()->adminCabang($this->origin)->create();

    expect(transferAbilities($admin, $this->requested))->toBe([
        'view' => true, 'approve' => true, 'reject' => true, 'ship' => false, 'receive' => false, 'cancel' => false,
    ])->and(transferAbilities($admin, $this->approved))->toBe([
        'view' => true, 'approve' => false, 'reject' => true, 'ship' => true, 'receive' => false, 'cancel' => false,
    ]);
});

it('lets the destination admin cancel and receive, but never approve its own request', function () {
    $admin = User::factory()->adminCabang($this->destination)->create();

    expect(transferAbilities($admin, $this->requested))->toBe([
        'view' => true, 'approve' => false, 'reject' => false, 'ship' => false, 'receive' => false, 'cancel' => true,
    ])->and(transferAbilities($admin, $this->inTransit))->toBe([
        'view' => true, 'approve' => false, 'reject' => false, 'ship' => false, 'receive' => true, 'cancel' => false,
    ]);
});

it('lets central staff decide and cancel, but leaves shipping and receiving to the warehouses', function () {
    $central = User::factory()->adminPusat()->create();

    expect(transferAbilities($central, $this->requested))->toBe([
        'view' => true, 'approve' => true, 'reject' => true, 'ship' => false, 'receive' => false, 'cancel' => true,
    ])->and($central->can('ship', $this->approved))->toBeFalse()
        ->and($central->can('receive', $this->inTransit))->toBeFalse();
});

it('gives a third branch nothing at all', function () {
    $outsider = User::factory()->adminCabang($this->outsider)->create();

    foreach ([$this->requested, $this->approved, $this->inTransit] as $transfer) {
        expect(array_filter(transferAbilities($outsider, $transfer)))->toBe([]);
    }
});

it('lets warehouse staff see and request transfers, and nothing more', function () {
    $destinationStaff = User::factory()->staffGudang($this->destination)->create();
    $originStaff = User::factory()->staffGudang($this->origin)->create();

    expect($destinationStaff->can('create', StockTransfer::class))->toBeTrue()
        ->and(transferAbilities($destinationStaff, $this->inTransit))->toBe([
            'view' => true, 'approve' => false, 'reject' => false, 'ship' => false, 'receive' => false, 'cancel' => false,
        ])
        ->and($destinationStaff->can('cancel', $this->requested))->toBeTrue()
        ->and(array_filter(transferAbilities($originStaff, $this->approved)))->toBe(['view' => true]);
});

it('refuses cashiers outright', function () {
    $cashier = User::factory()->kasir($this->destination)->create();

    expect($cashier->can('viewAny', StockTransfer::class))->toBeFalse()
        ->and($cashier->can('create', StockTransfer::class))->toBeFalse()
        ->and(array_filter(transferAbilities($cashier, $this->requested)))->toBe([]);
});

it('allows nothing on a finished transfer except reading it', function () {
    $originAdmin = User::factory()->adminCabang($this->origin)->create();
    $destinationAdmin = User::factory()->adminCabang($this->destination)->create();

    expect(array_filter(transferAbilities($originAdmin, $this->rejected)))->toBe(['view' => true])
        ->and(array_filter(transferAbilities($destinationAdmin, $this->rejected)))->toBe(['view' => true]);
});
