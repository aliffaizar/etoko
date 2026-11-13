<?php

namespace App\Support;

use App\Enums\StockTransferStatus;
use App\Models\Branch;
use App\Models\Stock;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\Warehouse;

/**
 * How stock transfers reach the screens (P2-E3-T3).
 *
 * The shapes mirror the TypeScript types in `resources/js/types/stock.ts`.
 */
final class StockTransferPresenter
{
    /**
     * One row of the listing.
     *
     * `direction` is relative to the branch the screen stands in, and null
     * when a central actor is looking at every branch at once.
     *
     * @return array<string, mixed>
     */
    public static function row(StockTransfer $transfer, ?int $perspectiveBranchId): array
    {
        return [
            'id' => $transfer->id,
            'code' => $transfer->code,
            'status' => $transfer->status->value,
            'status_label' => $transfer->status->label(),
            'direction' => match (true) {
                $transfer->isToBranch($perspectiveBranchId) => 'incoming',
                $transfer->isFromBranch($perspectiveBranchId) => 'outgoing',
                default => null,
            },
            'from_branch' => self::branch($transfer->fromBranch),
            'to_branch' => self::branch($transfer->toBranch),
            'item_count' => (int) $transfer->getAttribute('items_count'),
            'total_requested' => $transfer->totalRequested(),
            'requested_by' => $transfer->requestedBy?->name,
            'requested_at' => $transfer->requested_at?->toIso8601String(),
        ];
    }

    /**
     * The whole transfer, as its page reads it.
     *
     * The origin's shelf figures are only included when the viewer is the one
     * deciding or packing (`$showOriginStock`): the destination asked for the
     * goods, it is not entitled to read the origin's stock (BR-3).
     *
     * @return array<string, mixed>
     */
    public static function detail(StockTransfer $transfer, ?int $perspectiveBranchId, bool $showOriginStock): array
    {
        $originStock = $showOriginStock ? self::originStock($transfer) : null;

        return [
            ...self::row($transfer, $perspectiveBranchId),
            'item_count' => $transfer->items->count(),
            'from_warehouse' => self::warehouse($transfer->fromWarehouse),
            'to_warehouse' => self::warehouse($transfer->toWarehouse),
            'notes' => $transfer->notes,
            'rejection_reason' => $transfer->rejection_reason,
            'receipt_notes' => $transfer->receipt_notes,
            'total_discrepancy' => $transfer->totalDiscrepancy(),
            'steps' => self::steps($transfer),
            'items' => $transfer->items
                ->map(function (StockTransferItem $item) use ($originStock): array {
                    $stock = $originStock === null
                        ? null
                        : Stock::orZeroed($originStock[$item->product_variant_id] ?? null);

                    return [
                        'id' => $item->id,
                        'product_variant_id' => $item->product_variant_id,
                        'sku' => $item->productVariant->sku,
                        'display_name' => $item->productVariant->displayName(),
                        'product_name' => $item->productVariant->product->name,
                        'quantity_requested' => $item->quantity_requested,
                        'quantity_approved' => $item->quantity_approved,
                        'quantity_shipped' => $item->quantity_shipped,
                        'quantity_received' => $item->quantity_received,
                        'discrepancy' => $item->discrepancy(),
                        'origin_on_hand' => $stock?->quantity,
                        'origin_available' => $stock?->available_quantity,
                    ];
                })
                ->all(),
        ];
    }

    /**
     * The origin branch's warehouses, for the approver to choose the source.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function originWarehouseOptions(StockTransfer $transfer): array
    {
        return Warehouse::acrossBranches()
            ->where('branch_id', $transfer->from_branch_id)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->get()
            ->map(fn (Warehouse $warehouse): array => [
                'value' => (string) $warehouse->id,
                'label' => $warehouse->is_default ? "{$warehouse->name} (utama)" : $warehouse->name,
            ])
            ->all();
    }

    /**
     * Who took each step and when, in order, for the page's timeline.
     *
     * @return array<int, array{key: string, label: string, at: string|null, by: string|null}>
     */
    private static function steps(StockTransfer $transfer): array
    {
        $steps = [
            ['requested', 'Diminta', $transfer->requested_at, $transfer->requestedBy],
            ['approved', 'Disetujui', $transfer->approved_at, $transfer->approvedBy],
            ['rejected', 'Ditolak', $transfer->rejected_at, $transfer->rejectedBy],
            ['cancelled', 'Dibatalkan', $transfer->cancelled_at, $transfer->cancelledBy],
            ['shipped', 'Dikirim', $transfer->shipped_at, $transfer->shippedBy],
            [
                'received',
                $transfer->status === StockTransferStatus::PartiallyReceived ? 'Diterima sebagian' : 'Diterima',
                $transfer->received_at,
                $transfer->receivedBy,
            ],
        ];

        return collect($steps)
            ->filter(fn (array $step): bool => $step[2] !== null)
            ->map(fn (array $step): array => [
                'key' => $step[0],
                'label' => $step[1],
                'at' => $step[2]->toIso8601String(),
                'by' => $step[3] instanceof User ? $step[3]->name : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, Stock>
     */
    private static function originStock(StockTransfer $transfer): array
    {
        return Stock::acrossBranches()
            ->where('warehouse_id', $transfer->from_warehouse_id)
            ->whereIn('product_variant_id', $transfer->items->pluck('product_variant_id'))
            ->get()
            ->keyBy('product_variant_id')
            ->all();
    }

    /**
     * @return array{id: int, name: string, code: string}
     */
    private static function branch(Branch $branch): array
    {
        return ['id' => $branch->id, 'name' => $branch->name, 'code' => $branch->code];
    }

    /**
     * @return array{id: int, name: string, code: string}
     */
    private static function warehouse(Warehouse $warehouse): array
    {
        return ['id' => $warehouse->id, 'name' => $warehouse->name, 'code' => $warehouse->code];
    }
}
