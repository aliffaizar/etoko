<?php

use App\Enums\StockTransferStatus;
use App\Events\StockTransferAdvanced;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\StockTransferNotification;
use Illuminate\Support\Facades\Notification;

/*
 * Who hears about each step of a stock transfer (P2-E3-T6).
 */

beforeEach(function () {
    Notification::fake();

    $this->origin = Branch::factory()->create(['name' => 'Bandung']);
    $this->destination = Branch::factory()->create(['name' => 'Surabaya']);

    $this->transfer = StockTransfer::factory()->between(
        Warehouse::factory()->default()->for($this->origin)->create(),
        Warehouse::factory()->default()->for($this->destination)->create(),
    )->create();

    StockTransferItem::factory()->for($this->transfer)
        ->forVariant(Product::factory()->withDefaultVariant()->create()->variants()->sole())
        ->requested(7)
        ->create();
});

function advanceTo(StockTransfer $transfer, StockTransferStatus $status, ?User $actor = null): void
{
    $transfer->update(['status' => $status]);

    StockTransferAdvanced::dispatch($transfer->refresh(), $actor);
}

it('tells the origin approvers about a new request, and nobody else', function () {
    $originAdmin = User::factory()->adminCabang($this->origin)->create();
    $originStaff = User::factory()->staffGudang($this->origin)->create();
    $inactiveAdmin = User::factory()->adminCabang($this->origin)->inactive()->create();
    $destinationAdmin = User::factory()->adminCabang($this->destination)->create();
    $central = User::factory()->adminPusat()->create();

    advanceTo($this->transfer, StockTransferStatus::Requested, $destinationAdmin);

    Notification::assertSentTo($originAdmin, StockTransferNotification::class, function ($notification) use ($originAdmin) {
        $data = $notification->toDatabase($originAdmin);

        return $data['stock_transfer_id'] === $this->transfer->id
            && $data['message'] === "Surabaya meminta 7 unit dari Bandung ({$this->transfer->code}). Perlu persetujuan.";
    });
    Notification::assertNotSentTo([$originStaff, $inactiveAdmin, $destinationAdmin, $central], StockTransferNotification::class);
});

it('tells the destination receivers when the goods are on their way', function () {
    $receiver = User::factory()->adminCabang($this->destination)->create();
    $requesterStaff = User::factory()->staffGudang($this->destination)->create();
    $shipper = User::factory()->adminCabang($this->origin)->create();

    advanceTo($this->transfer, StockTransferStatus::InTransit, $shipper);

    Notification::assertSentTo($receiver, StockTransferNotification::class);
    Notification::assertNotSentTo([$requesterStaff, $shipper], StockTransferNotification::class);
});

it('tells both ends about an approval, except whoever approved it', function () {
    $originAdmin = User::factory()->adminCabang($this->origin)->create();
    $otherOriginAdmin = User::factory()->adminCabang($this->origin)->create();
    $destinationStaff = User::factory()->staffGudang($this->destination)->create();

    advanceTo($this->transfer, StockTransferStatus::Approved, $originAdmin);

    Notification::assertSentTo([$otherOriginAdmin, $destinationStaff], StockTransferNotification::class);
    Notification::assertNotSentTo($originAdmin, StockTransferNotification::class);
});

it('tells the requesting branch why its request was turned down', function () {
    $destinationStaff = User::factory()->staffGudang($this->destination)->create();
    $this->transfer->update(['rejection_reason' => 'Stok untuk grosir']);

    advanceTo($this->transfer, StockTransferStatus::Rejected);

    Notification::assertSentTo($destinationStaff, StockTransferNotification::class,
        fn ($notification) => str_ends_with($notification->toDatabase($destinationStaff)['message'], 'Stok untuk grosir'));
});

it('tells the origin what arrived', function () {
    $originAdmin = User::factory()->adminCabang($this->origin)->create();

    advanceTo($this->transfer, StockTransferStatus::Received);

    Notification::assertSentTo($originAdmin, StockTransferNotification::class);
});
