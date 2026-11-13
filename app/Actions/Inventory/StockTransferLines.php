<?php

namespace App\Actions\Inventory;

use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use Closure;
use Illuminate\Validation\ValidationException;

/**
 * Reads the per-line figures a transfer step was submitted with (P2-E3-T2).
 *
 * Approving, shipping and receiving all answer the same question for every
 * line — "how many, at this step?" — bounded by the figure of the step before.
 * The bound is checked here, against the locked rows, rather than trusted
 * from the form: the form was drawn before the lock was taken.
 *
 * Every line must be answered. A line left out is refused rather than read as
 * zero, because "I did not look at it" and "none of it" are different
 * answers, and only the second belongs on the document.
 */
final class StockTransferLines
{
    /**
     * @param  array<int, array{id: int, quantity: int}>  $submitted
     * @param  Closure(StockTransferItem): int  $ceiling  The most this step may say for a line.
     * @return array<int, int> Quantity by item id, for every line of the transfer.
     *
     * @throws ValidationException
     */
    public static function resolve(StockTransfer $transfer, array $submitted, Closure $ceiling, string $verb): array
    {
        $byId = [];

        foreach ($submitted as $line) {
            $byId[(int) $line['id']] = (int) $line['quantity'];
        }

        $quantities = [];

        foreach ($transfer->items as $item) {
            if (! array_key_exists($item->id, $byId)) {
                throw ValidationException::withMessages([
                    'items' => "Jumlah {$verb} untuk {$item->productVariant->sku} belum diisi. Isi 0 bila tidak ada.",
                ]);
            }

            $quantity = $byId[$item->id];
            $limit = $ceiling($item);

            if ($quantity < 0 || $quantity > $limit) {
                throw ValidationException::withMessages([
                    'items' => "Jumlah {$verb} untuk {$item->productVariant->sku} harus antara 0 dan {$limit}.",
                ]);
            }

            $quantities[$item->id] = $quantity;
            unset($byId[$item->id]);
        }

        if ($byId !== []) {
            throw ValidationException::withMessages(['items' => 'Ada baris yang bukan milik transfer ini.']);
        }

        return $quantities;
    }

    /**
     * The transfer, locked for this transaction, with what every step reads.
     *
     * Read across branches: the caller already resolved it through the scope
     * and the policy, and the lock must find the row whoever is asking.
     */
    public static function lock(StockTransfer $transfer): StockTransfer
    {
        return StockTransfer::acrossBranches()
            ->with(['items.productVariant', 'fromWarehouse', 'toWarehouse', 'fromBranch', 'toBranch'])
            ->whereKey($transfer->getKey())
            ->lockForUpdate()
            ->sole();
    }

    /**
     * @throws ValidationException
     */
    public static function refuse(?string $reason): void
    {
        if ($reason !== null) {
            throw ValidationException::withMessages(['status' => $reason]);
        }
    }
}
