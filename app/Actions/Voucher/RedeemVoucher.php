<?php

namespace App\Actions\Voucher;

use App\Exceptions\CheckoutFailedException;
use App\Models\Order;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use App\Services\Pricing\VoucherValidator;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Takes one unit of a voucher's quota for an order (P2-E5-T4).
 *
 * Two steps, both **inside** `PlaceOrder`'s transaction:
 *
 * 1. `claim()` — lock the voucher row and read again, under that lock,
 *    everything that limits redemption: whether it is still on, the quota, and
 *    how many times this customer has already used it. Every redemption of a
 *    voucher queues on the same row, so the count each one reads includes
 *    every redemption committed before it — the last unit of quota goes to
 *    exactly one of several simultaneous checkouts.
 * 2. `record()` — once the order row exists, write the usage and move
 *    `used_count`.
 *
 * **The claim has to come before the order is written**, and that is not
 * tidiness. `orders.voucher_id` is a foreign key, and inserting the order
 * takes a *shared* lock on the voucher row; claiming afterwards asks to raise
 * it to an exclusive one, and two checkouts each holding the shared lock wait
 * on each other until InnoDB kills one with a deadlock. Five simultaneous
 * checkouts did exactly that before the order of these steps was fixed.
 *
 * Lock order across checkout is therefore cart → voucher → order rows → stock,
 * and nothing else takes the voucher lock while holding a stock lock.
 */
final class RedeemVoucher
{
    /**
     * Lock the voucher and confirm a unit is still there for this customer.
     *
     * @throws CheckoutFailedException
     */
    public function claim(Voucher $voucher, ?User $customer): Voucher
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('RedeemVoucher must run inside the transaction that writes the order.');
        }

        $locked = Voucher::query()->whereKey($voucher->getKey())->lockForUpdate()->first();

        if (! $locked instanceof Voucher || ! $locked->is_active) {
            throw CheckoutFailedException::voucherRejected("Voucher {$voucher->code} sudah tidak berlaku.");
        }

        if ($locked->isQuotaExhausted()) {
            throw CheckoutFailedException::voucherRejected(
                "Kuota voucher {$locked->code} baru saja habis dipakai pembeli lain. Hapus voucher untuk melanjutkan tanpa potongan.",
            );
        }

        if ($customer instanceof User
            && $locked->per_user_limit > 0
            && VoucherValidator::usesBy($locked, $customer) >= $locked->per_user_limit) {
            throw CheckoutFailedException::voucherRejected("Anda sudah memakai voucher {$locked->code} sebanyak batas yang diizinkan.");
        }

        return $locked;
    }

    /**
     * Write the redemption for an order, against a voucher `claim()` locked.
     */
    public function record(Voucher $claimed, Order $order, ?User $customer, int $discount): VoucherUsage
    {
        $usage = VoucherUsage::create([
            'voucher_id' => $claimed->getKey(),
            'user_id' => $customer?->getKey(),
            'order_id' => $order->getKey(),
            'discount_amount' => $discount,
            'used_at' => now(),
        ]);

        $claimed->increment('used_count');

        return $usage;
    }

    /**
     * Both steps at once, for an order that already exists.
     *
     * @throws CheckoutFailedException
     */
    public function handle(Voucher $voucher, Order $order, ?User $customer, int $discount): VoucherUsage
    {
        return $this->record($this->claim($voucher, $customer), $order, $customer, $discount);
    }
}
