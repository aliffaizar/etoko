<?php

namespace App\Actions\Voucher;

use App\Models\User;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a voucher with its branch, category and product lists
 * (P2-E5-T2).
 *
 * One action for both, because the two differ only in whether a row exists
 * yet; the voucher and its lists are written in one transaction, so a voucher
 * is never live for a moment with the narrowing it was saved with missing.
 *
 * `used_count` is never written here — only `RedeemVoucher` and
 * `ReleaseVoucher` move it, under the row lock.
 */
final class SaveVoucher
{
    /**
     * @param  array{attributes: array<string, mixed>, branch_ids: array<int, int>, category_ids: array<int, int>, product_ids: array<int, int>}  $data
     */
    public function handle(array $data, ?Voucher $voucher = null, ?User $actor = null): Voucher
    {
        return DB::transaction(function () use ($data, $voucher, $actor): Voucher {
            $attributes = $data['attributes'];
            unset($attributes['used_count']);

            if ($voucher instanceof Voucher) {
                $voucher->update($attributes);
            } else {
                $voucher = Voucher::create([...$attributes, 'created_by' => $actor?->getKey()]);
            }

            $voucher->branches()->sync($data['branch_ids']);
            $voucher->categories()->sync($data['category_ids']);
            $voucher->products()->sync($data['product_ids']);

            return $voucher;
        });
    }
}
