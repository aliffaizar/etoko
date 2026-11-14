<?php

namespace App\Services\Pricing;

use App\Enums\FulfillmentType;
use App\Enums\OrderChannel;
use App\Enums\VoucherAppliesTo;
use App\Enums\VoucherChannel;
use App\Enums\VoucherType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * Decides whether a voucher may be used on a basket, and for how much
 * (P2-E5-T3).
 *
 * The **one** place the rules live, asked three times with the same lines:
 * when the code is applied on the cart screen, when the checkout screen draws
 * its totals, and by `PlaceOrder` before it writes the order. Every refusal is
 * a sentence a customer can act on, in the order they would want to hear it —
 * nobody should be told their basket is too small for a voucher that ended
 * last week.
 *
 * What it does **not** do is take the quota: that is `RedeemVoucher`, under
 * the voucher's row lock, because a count read here can be stale by the time
 * the order is written (P2-E5-T4). The quota and per-customer checks here are
 * the early, friendly answer; the locked one is the binding answer.
 *
 * Rules worth knowing (ADR-064):
 *
 * - the minimum spend is measured against the **covered** lines, the same
 *   figure the discount is a share of;
 * - a percentage is rounded **down** to the rupiah, then capped by
 *   `max_discount`; a fixed amount never exceeds the covered goods;
 * - free shipping needs a delivery and a delivery charge; until the charge is
 *   known (`$shippingCost` null) the voucher is accepted with
 *   `shippingDiscount` null, and a pickup refuses it;
 * - a voucher always needs a signed-in customer, because every voucher has a
 *   per-customer allowance to count against, even an unlimited one.
 */
final class VoucherValidator
{
    /**
     * @param  array<int, VoucherLine>  $lines  Keyed however the caller likes; the line discounts come back with the same keys.
     * @param  int|null  $shippingCost  The delivery charge, or null when it is not known yet.
     */
    public function check(
        Voucher $voucher,
        array $lines,
        ?Branch $branch,
        ?User $customer,
        OrderChannel $channel = OrderChannel::Online,
        ?FulfillmentType $fulfillment = null,
        ?int $shippingCost = null,
        ?CarbonInterface $now = null,
    ): VoucherOutcome {
        $reason = $this->availabilityRejection($voucher, $branch, $customer, $channel, $now ?? now());

        if ($reason !== null) {
            return VoucherOutcome::rejected($voucher, $reason);
        }

        $covered = $this->coveredLines($voucher, $lines);

        if ($covered === []) {
            return VoucherOutcome::rejected($voucher, "Voucher {$voucher->code} tidak berlaku untuk produk di keranjang Anda.");
        }

        $eligible = array_sum(array_map(fn (VoucherLine $line): int => $line->subtotal, $covered));

        if ($eligible < $voucher->min_order_amount) {
            return VoucherOutcome::rejected($voucher, $this->minimumRejection($voucher, $eligible));
        }

        if ($voucher->type->discountsShipping()) {
            return $this->shippingOutcome($voucher, $eligible, $fulfillment, $shippingCost);
        }

        $discount = $this->goodsDiscount($voucher, $eligible);

        return new VoucherOutcome(
            voucher: $voucher,
            eligibleSubtotal: $eligible,
            goodsDiscount: $discount,
            lineDiscounts: self::allocate($discount, $covered),
        );
    }

    /**
     * The refusals that do not depend on what is in the basket.
     */
    private function availabilityRejection(
        Voucher $voucher,
        ?Branch $branch,
        ?User $customer,
        OrderChannel $channel,
        CarbonInterface $now,
    ): ?string {
        $code = $voucher->code;

        if (! $voucher->is_active) {
            return "Voucher {$code} sedang tidak aktif.";
        }

        if ($voucher->starts_at !== null && $voucher->starts_at->greaterThan($now)) {
            return "Voucher {$code} baru berlaku mulai ".self::moment($voucher->starts_at).'.';
        }

        if ($voucher->ends_at !== null && $voucher->ends_at->lessThan($now)) {
            return "Voucher {$code} sudah berakhir pada ".self::moment($voucher->ends_at).'.';
        }

        if (! $voucher->channel->allows($channel)) {
            return $voucher->channel === VoucherChannel::Pos
                ? "Voucher {$code} hanya berlaku untuk belanja langsung di kasir toko."
                : "Voucher {$code} hanya berlaku untuk belanja online.";
        }

        if ($reason = $this->branchRejection($voucher, $branch)) {
            return $reason;
        }

        if ($voucher->isQuotaExhausted()) {
            return "Kuota voucher {$code} sudah habis.";
        }

        if (! $customer instanceof User) {
            return 'Masuk ke akun Anda untuk memakai voucher.';
        }

        if ($voucher->per_user_limit > 0 && self::usesBy($voucher, $customer) >= $voucher->per_user_limit) {
            return $voucher->per_user_limit === 1
                ? "Anda sudah pernah memakai voucher {$code}."
                : "Anda sudah memakai voucher {$code} sebanyak {$voucher->per_user_limit} kali, batas untuk setiap pelanggan.";
        }

        return null;
    }

    private function branchRejection(Voucher $voucher, ?Branch $branch): ?string
    {
        $branchIds = $voucher->relationLoaded('branches')
            ? $voucher->branches->modelKeys()
            : $voucher->branches()->pluck('branches.id')->all();

        if ($branchIds === []) {
            return null;
        }

        if (! $branch instanceof Branch) {
            return "Pilih cabang belanja dulu: voucher {$voucher->code} hanya berlaku di cabang tertentu.";
        }

        if (! in_array($branch->getKey(), $branchIds, strict: true)) {
            return "Voucher {$voucher->code} tidak berlaku di cabang {$branch->name}.";
        }

        return null;
    }

    /**
     * How many live redemptions this customer has made of this voucher.
     *
     * Public because `RedeemVoucher` counts the same way under the lock.
     */
    public static function usesBy(Voucher $voucher, User $customer): int
    {
        return VoucherUsage::query()
            ->live()
            ->where('voucher_id', $voucher->getKey())
            ->where('user_id', $customer->getKey())
            ->count();
    }

    /**
     * The lines the voucher covers, keys preserved.
     *
     * @param  array<int, VoucherLine>  $lines
     * @return array<int, VoucherLine>
     */
    private function coveredLines(Voucher $voucher, array $lines): array
    {
        return match ($voucher->applies_to) {
            VoucherAppliesTo::All => $lines,
            VoucherAppliesTo::Product => $this->linesMatching($lines, $voucher->products()->pluck('products.id')->all(), 'productId'),
            VoucherAppliesTo::Category => $this->linesMatching($lines, $this->coveredCategoryIds($voucher), 'categoryId'),
        };
    }

    /**
     * @param  array<int, VoucherLine>  $lines
     * @param  array<int, int>  $ids
     * @param  'productId'|'categoryId'  $field
     * @return array<int, VoucherLine>
     */
    private function linesMatching(array $lines, array $ids, string $field): array
    {
        $wanted = array_fill_keys($ids, true);

        return array_filter($lines, fn (VoucherLine $line): bool => isset($wanted[$line->{$field}]));
    }

    /**
     * The voucher's categories and every subcategory beneath them.
     *
     * @return array<int, int>
     */
    private function coveredCategoryIds(Voucher $voucher): array
    {
        $ids = [];

        foreach ($voucher->categories()->get() as $category) {
            /** @var Category $category */
            $ids = [...$ids, $category->getKey(), ...$category->descendantIds()];
        }

        return array_values(array_unique($ids));
    }

    private function minimumRejection(Voucher $voucher, int $eligible): string
    {
        $minimum = Money::format($voucher->min_order_amount);
        $short = Money::format($voucher->min_order_amount - $eligible);
        $scope = $voucher->applies_to === VoucherAppliesTo::All ? '' : ' untuk produk yang termasuk voucher ini';

        return "Voucher {$voucher->code} butuh belanja minimal {$minimum}{$scope}. Tambah {$short} lagi untuk memakainya.";
    }

    private function goodsDiscount(Voucher $voucher, int $eligible): int
    {
        $discount = match ($voucher->type) {
            VoucherType::Percentage => intdiv($eligible * min($voucher->value, 100), 100),
            VoucherType::Fixed => $voucher->value,
            VoucherType::FreeShipping => 0,
        };

        if ($voucher->max_discount !== null) {
            $discount = min($discount, $voucher->max_discount);
        }

        return min($discount, $eligible);
    }

    private function shippingOutcome(
        Voucher $voucher,
        int $eligible,
        ?FulfillmentType $fulfillment,
        ?int $shippingCost,
    ): VoucherOutcome {
        if ($fulfillment !== null && ! $fulfillment->requiresShipping()) {
            return VoucherOutcome::rejected(
                $voucher,
                "Voucher {$voucher->code} gratis ongkir hanya berlaku untuk pesanan yang dikirim, bukan diambil di toko.",
            );
        }

        $discount = $shippingCost === null
            ? null
            : min($shippingCost, $voucher->max_discount ?? $shippingCost);

        return new VoucherOutcome(
            voucher: $voucher,
            eligibleSubtotal: $eligible,
            shippingDiscount: $discount,
        );
    }

    /**
     * Share a discount across the covered lines in proportion to their value.
     *
     * Each line gets the rounded-down share, and the rupiah left over go one
     * at a time to the largest lines, so the shares add up to the discount
     * exactly and no line is discounted beyond its own subtotal.
     *
     * @param  array<int, VoucherLine>  $lines
     * @return array<int, int>
     */
    public static function allocate(int $discount, array $lines): array
    {
        $total = array_sum(array_map(fn (VoucherLine $line): int => $line->subtotal, $lines));

        if ($discount <= 0 || $total <= 0) {
            return array_map(fn (): int => 0, $lines);
        }

        $shares = array_map(fn (VoucherLine $line): int => intdiv($line->subtotal * $discount, $total), $lines);
        $left = $discount - array_sum($shares);

        $largestFirst = $lines;
        uasort($largestFirst, fn (VoucherLine $a, VoucherLine $b): int => $b->subtotal <=> $a->subtotal);

        while ($left > 0) {
            foreach (array_keys($largestFirst) as $key) {
                if ($left === 0) {
                    break;
                }

                if ($shares[$key] < $lines[$key]->subtotal) {
                    $shares[$key]++;
                    $left--;
                }
            }
        }

        return $shares;
    }

    private static function moment(CarbonInterface $moment): string
    {
        return $moment->copy()->timezone((string) config('app.timezone'))->translatedFormat('j F Y, H:i');
    }
}
