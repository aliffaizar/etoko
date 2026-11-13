<?php

namespace Database\Seeders;

use App\Actions\Inventory\ApproveStockTransfer;
use App\Actions\Inventory\ReceiveStockTransfer;
use App\Actions\Inventory\RequestStockTransfer;
use App\Actions\Inventory\ShipStockTransfer;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Stock;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

/**
 * Three stock transfers for local development, one at each interesting step
 * (P2-E3): a request waiting for approval, a parcel on the road, and a
 * delivery that arrived short.
 *
 * Built through the transfer Actions, so every unit that moved has the
 * `transfer_out`/`transfer_in` rows that explain it, and the notifications
 * each step sends land in the branch dashboards as they would in use.
 *
 * Skipped when any transfer exists, so re-running never stacks them up.
 * Never run it against a production database.
 */
class StockTransferDemoSeeder extends Seeder
{
    public function run(
        RequestStockTransfer $request,
        ApproveStockTransfer $approve,
        ShipStockTransfer $ship,
        ReceiveStockTransfer $receive,
    ): void {
        if (StockTransfer::acrossBranches()->exists()) {
            return;
        }

        $warehouses = Branch::query()->ordered()->limit(3)->get()
            ->map(fn (Branch $branch): ?Warehouse => Warehouse::acrossBranches()
                ->where('branch_id', $branch->id)->where('is_default', true)->first())
            ->filter()
            ->values();

        if ($warehouses->count() < 3) {
            return;
        }

        [$first, $second, $third] = $warehouses->all();

        // Waiting on the origin.
        $this->ask($request, $second, $first, 4, 'Stok menipis menjelang akhir pekan.');

        // Approved and shipped: on the road, counted nowhere.
        $onTheRoad = $this->ask($request, $third, $second, 3, 'Untuk pajangan toko.');
        $approve->handle($onTheRoad, ['items' => $this->lines($onTheRoad, 'quantity_requested')], $this->staffOf($second));
        $ship->handle($onTheRoad, $this->lines($onTheRoad, 'quantity_approved'), $this->staffOf($second));

        // Arrived one unit short on its first line.
        $short = $this->ask($request, $first, $third, 2, null);
        $approve->handle($short, ['items' => $this->lines($short, 'quantity_requested')], $this->staffOf($third));
        $ship->handle($short, $this->lines($short, 'quantity_approved'), $this->staffOf($third));
        $shipped = $this->lines($short, 'quantity_shipped');
        $arrived = array_map(
            fn (array $line, int $position): array => [
                'id' => $line['id'],
                'quantity' => $position === 0 ? $line['quantity'] - 1 : $line['quantity'],
            ],
            $shipped,
            array_keys($shipped),
        );
        $receive->handle($short, $arrived, 'Satu unit pecah dalam perjalanan.', $this->staffOf($first));
    }

    /**
     * A request from `$to` for `$quantity` units of a few variants `$from` holds.
     */
    private function ask(RequestStockTransfer $request, Warehouse $to, Warehouse $from, int $quantity, ?string $notes): StockTransfer
    {
        $variantIds = Stock::acrossBranches()
            ->where('warehouse_id', $from->id)
            ->whereRaw('quantity - reserved_quantity >= ?', [$quantity + 5])
            ->orderBy('product_variant_id')
            ->limit(2)
            ->pluck('product_variant_id');

        return $request->handle($to, $from->branch, [
            'notes' => $notes,
            'items' => $variantIds->map(fn (int $id): array => ['product_variant_id' => $id, 'quantity' => $quantity])->all(),
        ], $this->staffOf($to));
    }

    /**
     * @return array<int, array{id: int, quantity: int}>
     */
    private function lines(StockTransfer $transfer, string $column): array
    {
        return $transfer->items()->get()
            ->map(fn (StockTransferItem $item): array => ['id' => $item->id, 'quantity' => (int) $item->{$column}])
            ->all();
    }

    private function staffOf(Warehouse $warehouse): ?User
    {
        return User::query()
            ->where('branch_id', $warehouse->branch_id)
            ->whereHas('roles', fn ($roles) => $roles->where('name', UserRole::AdminCabang->value))
            ->first();
    }
}
