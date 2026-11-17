<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * One stored notification, as the bell and the notification centre read it
 * (P2-E6-T4).
 *
 * Every notification already stores its own sentence in `data.message`, so
 * this only adds the two things a list needs and a payload cannot know: a
 * title for the kind of thing it is, and a link to the screen where it can be
 * acted on. The link is built here, from the ids the payload copied, rather
 * than stored — a stored URL would go stale the day a route is renamed.
 *
 * An unknown `type` still renders, with no link: a notification written by a
 * class that has since been removed is old news, not an error.
 *
 * The link depends on who is reading, because one notification can reach
 * people with different screens: "pesanan perlu diproses" opens the order for
 * staff who can read orders, and the packing list for warehouse staff who only
 * hold `orders.pack` (NTF-02, ADR-062) — the order screen would be a 403 for
 * them.
 */
final class NotificationPresenter
{
    /**
     * @return array{id: string, type: string, title: string, message: string, url: string|null, is_read: bool, created_at: string|null}
     */
    public static function item(DatabaseNotification $notification, User $viewer): array
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;
        $type = (string) ($data['type'] ?? 'unknown');

        return [
            'id' => $notification->id,
            'type' => $type,
            'title' => self::title($type),
            'message' => (string) ($data['message'] ?? ''),
            'url' => self::url($type, $data, $viewer),
            'is_read' => $notification->read_at !== null,
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }

    private static function title(string $type): string
    {
        return match ($type) {
            'low_stock' => 'Stok menipis',
            'stock_transfer' => 'Transfer stok',
            'order_awaiting_branch' => 'Pesanan perlu diproses',
            'order_status' => 'Status pesanan',
            'order_ready_for_pickup' => 'Pesanan siap diambil',
            'report_export' => 'Export laporan',
            default => 'Notifikasi',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function url(string $type, array $data, User $viewer): ?string
    {
        return match ($type) {
            'low_stock' => isset($data['product_variant_id'], $data['warehouse_id'])
                ? route('branch.stocks.movements', [
                    'variant' => $data['product_variant_id'],
                    'warehouse' => $data['warehouse_id'],
                ])
                : null,
            'stock_transfer' => isset($data['stock_transfer_id'])
                ? route('branch.stock-transfers.show', $data['stock_transfer_id'])
                : null,
            'order_awaiting_branch' => self::awaitingOrderUrl($data, $viewer),
            'report_export' => isset($data['file'])
                ? route('admin.reports.exports.download', ['file' => $data['file']])
                : null,
            'order_status', 'order_ready_for_pickup' => isset($data['order_id'])
                ? route('shop.account.orders.show', $data['order_id'])
                : null,
            default => null,
        };
    }

    /**
     * Where "pesanan perlu diproses" takes this reader.
     *
     * @param  array<string, mixed>  $data
     */
    private static function awaitingOrderUrl(array $data, User $viewer): ?string
    {
        if (! isset($data['order_id'])) {
            return null;
        }

        if ($viewer->can('orders.view')) {
            return route('admin.orders.show', $data['order_id']);
        }

        return $viewer->can('orders.pack') ? route('branch.packing.index') : null;
    }
}
