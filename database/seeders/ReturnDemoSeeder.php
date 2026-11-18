<?php

namespace Database\Seeders;

use App\Actions\Returns\ApproveReturn;
use App\Actions\Returns\RejectReturn;
use App\Actions\Returns\RequestReturn;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReturnReason;
use App\Enums\ReturnType;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Validation\ValidationException;

/**
 * Return requests for the demo (P2-E4), one waiting for the branch, one
 * approved and waiting for the parcel, and one rejected — so the returns
 * screen has a row in each of the states a branch acts on.
 *
 * Opened by the customer who owns the order through `RequestReturn`, and
 * decided through `ApproveReturn` / `RejectReturn`, the actions the panel
 * uses. Nothing here moves stock or money: that happens only when a parcel
 * is received, which is left for whoever is trying the demo.
 *
 * Skipped when any return exists. Never run it against a production database.
 */
class ReturnDemoSeeder extends Seeder
{
    /**
     * What each demo return says, and what the branch decides.
     *
     * @var array<int, array{reason: ReturnReason, type: ReturnType, description: string, decision: string|null, photo: bool}>
     */
    private const SCENARIOS = [
        [
            'reason' => ReturnReason::Damaged,
            'type' => ReturnType::Refund,
            'description' => 'Barang sampai dengan kemasan penyok dan unitnya retak di bagian samping.',
            'decision' => null,
            'photo' => true,
        ],
        [
            'reason' => ReturnReason::WrongItem,
            'type' => ReturnType::Exchange,
            'description' => 'Yang saya pesan warna hitam, yang datang warna putih. Mohon ditukar.',
            'decision' => 'approve',
            'photo' => false,
        ],
        [
            'reason' => ReturnReason::ChangedMind,
            'type' => ReturnType::Refund,
            'description' => 'Ternyata kurang cocok dengan kebutuhan saya.',
            'decision' => 'reject',
            'photo' => false,
        ],
    ];

    public function run(RequestReturn $requestReturn, ApproveReturn $approve, RejectReturn $reject): void
    {
        if (OrderReturn::acrossBranches()->exists()) {
            return;
        }

        $orders = Order::acrossBranches()
            ->whereIn('status', [OrderStatus::Delivered, OrderStatus::Completed])
            ->where('payment_status', PaymentStatus::Paid)
            ->with('items')
            ->latest('placed_at')
            ->limit(count(self::SCENARIOS))
            ->get();

        foreach ($orders as $index => $order) {
            $scenario = self::SCENARIOS[$index];
            $customer = User::query()->find($order->user_id);
            $line = $order->items->first();

            if (! $customer instanceof User || $line === null) {
                continue;
            }

            $photo = $scenario['photo']
                ? DemoImages::store(OrderReturn::PHOTO_DIRECTORY.'/'.$order->getKey(), 'Foto kerusakan', 640, 480)
                : null;

            try {
                $return = $requestReturn->handle($order, $customer, [
                    'type' => $scenario['type'],
                    'reason' => $scenario['reason'],
                    'description' => $scenario['description'],
                    'photo_paths' => array_filter([$photo]),
                    'items' => [['order_item_id' => $line->getKey(), 'quantity' => 1]],
                ]);
            } catch (ValidationException) {
                continue;
            }

            $staff = $this->branchAdminOf($order->branch_id);

            match ($scenario['decision']) {
                'approve' => $approve->handle($return, $staff, 'Disetujui, silakan kirim barang ke cabang.'),
                'reject' => $reject->handle($return, 'Alasan berubah pikiran tidak termasuk ketentuan retur.', $staff),
                default => null,
            };
        }
    }

    private function branchAdminOf(int $branchId): ?User
    {
        return User::query()
            ->where('branch_id', $branchId)
            ->role(UserRole::AdminCabang->value)
            ->first();
    }
}
