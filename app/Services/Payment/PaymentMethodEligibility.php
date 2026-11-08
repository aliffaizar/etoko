<?php

namespace App\Services\Payment;

use App\Enums\OrderChannel;
use App\Models\PaymentMethod;
use App\Services\Payment\Data\PaymentContext;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which ways of paying an order may use (P2-E1-T7).
 *
 * One class, consulted twice: the checkout screen asks it which methods to
 * **offer**, and `InitiatePayment` asks it whether the one that came back is
 * still allowed. A single set of rules in a single place is what keeps those
 * two answers from differing — a method offered and then refused is worse than
 * one never offered, because the customer has already decided.
 *
 * Each rule answers with a *sentence*, not a boolean. A refusal that says
 * "COD tidak tersedia" leaves the customer guessing between the branch, the
 * city and the amount; one that says the total is above the COD ceiling tells
 * them to transfer instead. The sentence is the product, the boolean is a
 * by-product.
 *
 * The rules, in the order they are checked — cheapest and most general first:
 *
 * 1. **Switched off.** An inactive method is not offered to anybody.
 * 2. **Not for the web.** `cash` is a counter transaction (Phase 3 POS).
 * 3. **Wrong way of receiving the goods.** COD names `delivery`; there is
 *    nobody to pay on the doorstep of a shop you walked into.
 * 4. **Wrong branch.** `config.branch_ids`, empty meaning everywhere.
 * 5. **Wrong destination.** `config.cities` / `config.provinces`, matched
 *    against the order's address snapshots case-insensitively, because the
 *    customer typed them. Skipped entirely for a pickup, which has no
 *    destination.
 * 6. **Wrong size.** `min_amount` / `max_amount` against the order's
 *    `grand_total` — the goods, not the goods plus the method's own fee.
 */
final class PaymentMethodEligibility
{
    /**
     * Why this method may not be used, or null when it may.
     */
    public function reasonAgainst(PaymentMethod $method, PaymentContext $context): ?string
    {
        if (! $method->is_active) {
            return 'metode ini sedang dinonaktifkan.';
        }

        if ($context->channel === OrderChannel::Online && ! $method->type->isAvailableOnline()) {
            return 'metode ini hanya untuk pembayaran di kasir cabang.';
        }

        return $this->fulfillmentReason($method, $context)
            ?? $this->branchReason($method, $context)
            ?? $this->destinationReason($method, $context)
            ?? $this->amountReason($method, $context);
    }

    /**
     * Whether this method may be used for this order.
     */
    public function allows(PaymentMethod $method, PaymentContext $context): bool
    {
        return $this->reasonAgainst($method, $context) === null;
    }

    /**
     * Narrow a list of methods to the ones this order may use.
     *
     * Takes a loaded collection rather than a query on purpose: the caller has
     * usually just read every active method for the screen, and filtering in
     * PHP keeps the rules in one place instead of splitting them between here
     * and a set of `where` clauses that would have to agree with them. There
     * are a handful of payment methods in a shop, not thousands.
     *
     * @param  Collection<int, PaymentMethod>  $methods
     * @return Collection<int, PaymentMethod>
     */
    public function filter(Collection $methods, PaymentContext $context): Collection
    {
        return $methods->filter(fn (PaymentMethod $method): bool => $this->allows($method, $context))->values();
    }

    private function fulfillmentReason(PaymentMethod $method, PaymentContext $context): ?string
    {
        $allowed = $method->allowedFulfillmentTypes();

        if ($allowed === [] || in_array($context->fulfillmentType->value, $allowed, strict: true)) {
            return null;
        }

        return 'metode ini tidak tersedia untuk '.mb_strtolower($context->fulfillmentType->label()).'.';
    }

    private function branchReason(PaymentMethod $method, PaymentContext $context): ?string
    {
        $allowed = $method->allowedBranchIds();

        if ($allowed === [] || in_array($context->branchId, $allowed, strict: true)) {
            return null;
        }

        return 'metode ini belum tersedia di cabang yang memproses pesanan Anda.';
    }

    /**
     * The destination fence, which a pickup is exempt from.
     *
     * A city list and a province list are both satisfied by *either* matching,
     * not both: a shop that lists "Jakarta Selatan" and the province
     * "DKI Jakarta" means "these cities, plus anywhere in this province",
     * which is how somebody writing the configuration would read it.
     */
    private function destinationReason(PaymentMethod $method, PaymentContext $context): ?string
    {
        $cities = $method->allowedCities();
        $provinces = $method->allowedProvinces();

        if ($cities === [] && $provinces === []) {
            return null;
        }

        if (! $context->fulfillmentType->requiresShipping()) {
            return null;
        }

        $city = $this->normalise($context->cityName);
        $province = $this->normalise($context->provinceName);

        if ($city !== null && in_array($city, $cities, strict: true)) {
            return null;
        }

        if ($province !== null && in_array($province, $provinces, strict: true)) {
            return null;
        }

        return 'metode ini belum melayani wilayah tujuan pengiriman Anda.';
    }

    private function amountReason(PaymentMethod $method, PaymentContext $context): ?string
    {
        if ($method->min_amount !== null && $context->amount < $method->min_amount) {
            return 'total pesanan di bawah batas minimum '.Money::format($method->min_amount).'.';
        }

        if ($method->max_amount !== null && $context->amount > $method->max_amount) {
            return 'total pesanan di atas batas maksimum '.Money::format($method->max_amount).'.';
        }

        return null;
    }

    private function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalised = mb_strtolower(trim($value));

        return $normalised === '' ? null : $normalised;
    }
}
