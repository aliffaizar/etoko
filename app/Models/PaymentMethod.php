<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\PaymentDriver;
use App\Enums\PaymentMethodType;
use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A way of paying (P2-E1-T1).
 *
 * Company-wide reference data with no branch of its own: "BCA transfer" is the
 * same arrangement everywhere. Where a method may be *used* is a rule rather
 * than an owner, and the rules live in `config` — read here through named
 * accessors so no caller ever digs into the JSON itself.
 *
 * Deliberately **not** branch-scoped, and deliberately not `BelongsToBranch`:
 * every branch offers the same methods, and a branch that must not take COD
 * says so through `config.branch_ids` (`P2-E1-T7`) rather than by owning a row.
 *
 * `Auditable`, because changing a bank account number or switching a method
 * off is a money decision somebody has to be able to trace (BR-9).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property PaymentMethodType $type
 * @property PaymentDriver $driver
 * @property string|null $provider_code
 * @property string|null $instructions
 * @property string|null $logo_path
 * @property int $fee_flat
 * @property string $fee_percent
 * @property int|null $min_amount
 * @property int|null $max_amount
 * @property bool $is_active
 * @property int $sort_order
 * @property array<string, mixed>|null $config
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Payment> $payments
 */
#[Fillable([
    'code', 'name', 'type', 'driver', 'provider_code', 'instructions',
    'logo_path', 'fee_flat', 'fee_percent', 'min_amount', 'max_amount',
    'is_active', 'sort_order', 'config',
])]
class PaymentMethod extends Model
{
    /** @use HasFactory<PaymentMethodFactory> */
    use Auditable, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PaymentMethodType::class,
            'driver' => PaymentDriver::class,
            'fee_flat' => 'integer',
            'min_amount' => 'integer',
            'max_amount' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'config' => 'array',
        ];
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * What this method adds to the order's total, in whole rupiah.
     *
     * Rounded rather than truncated, and rounded once at the end: a percentage
     * fee on a 358.000 order is not a whole number, and the figure the
     * customer is asked for has to be one.
     *
     * The fee belongs to the payment attempt rather than to the order
     * (ADR-039), so this is called by `InitiatePayment` and recorded on the
     * `payments` row — never written back onto `orders.grand_total`.
     */
    public function feeFor(int $amount): int
    {
        return $this->fee_flat + (int) round($amount * (float) $this->fee_percent / 100);
    }

    /**
     * The total this method would charge for an order of `$amount`.
     */
    public function chargeFor(int $amount): int
    {
        return $amount + $this->feeFor($amount);
    }

    /**
     * Whether this method charges anything on top of the order.
     */
    public function hasFee(): bool
    {
        return $this->fee_flat > 0 || (float) $this->fee_percent > 0;
    }

    /**
     * The bank account a manual transfer should be sent to.
     *
     * Null for every method that is not a transfer into an account we own, and
     * null for a transfer whose account has not been configured yet — which
     * the instructions screen says out loud rather than printing a blank line.
     *
     * @return array{name: string, account_number: string, account_holder: string}|null
     */
    public function bankAccount(): ?array
    {
        $bank = $this->config['bank'] ?? null;

        if (! is_array($bank)) {
            return null;
        }

        $name = $bank['name'] ?? null;
        $number = $bank['account_number'] ?? null;
        $holder = $bank['account_holder'] ?? null;

        if (! is_string($name) || ! is_string($number) || ! is_string($holder)) {
            return null;
        }

        return ['name' => $name, 'account_number' => $number, 'account_holder' => $holder];
    }

    /**
     * The branches this method is offered at, or an empty list for all of them.
     *
     * Empty means "everywhere" on purpose: a method open to the whole company
     * then carries no configuration at all, and a mistyped key fences nothing
     * off instead of silently fencing everything off.
     *
     * @return array<int, int>
     */
    public function allowedBranchIds(): array
    {
        return array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            array_filter(
                is_array($this->config['branch_ids'] ?? null) ? $this->config['branch_ids'] : [],
                static fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)),
            ),
        ));
    }

    /**
     * The cities this method delivers to, or an empty list for all of them.
     *
     * Compared against the order's `shipping_city_name` snapshot, which is
     * typed by the customer, so the comparison is case- and
     * whitespace-insensitive. Regions get their own table in `P2-E2-T3`; until
     * then the address *is* its text, and fencing COD by city name is the
     * honest version of what the data supports.
     *
     * @return array<int, string>
     */
    public function allowedCities(): array
    {
        return $this->normalisedList('cities');
    }

    /**
     * The provinces this method delivers to, or an empty list for all of them.
     *
     * @return array<int, string>
     */
    public function allowedProvinces(): array
    {
        return $this->normalisedList('provinces');
    }

    /**
     * The ways of receiving goods this method works with, or all of them.
     *
     * @return array<int, string>
     */
    public function allowedFulfillmentTypes(): array
    {
        return $this->normalisedList('fulfillment_types');
    }

    /**
     * The Snap payment channels this method opens (`P2-E1-T5`).
     *
     * @return array<int, string>
     */
    public function midtransEnabledPayments(): array
    {
        $midtrans = $this->config['midtrans'] ?? null;

        if (! is_array($midtrans) || ! is_array($midtrans['enabled_payments'] ?? null)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $channel): string => is_string($channel) ? $channel : '',
                $midtrans['enabled_payments'],
            ),
            static fn (string $channel): bool => $channel !== '',
        ));
    }

    /**
     * The public URL of the method's logo, when it has one.
     */
    public function logoUrl(): ?string
    {
        return $this->logo_path === null ? null : Storage::disk('public')->url($this->logo_path);
    }

    /**
     * A lowercased, trimmed list from `config`, with blanks dropped.
     *
     * @return array<int, string>
     */
    private function normalisedList(string $key): array
    {
        $values = $this->config[$key] ?? null;

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $value): string => is_string($value) ? mb_strtolower(trim($value)) : '',
                $values,
            ),
            static fn (string $value): bool => $value !== '',
        ));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The order methods are offered in: the shop's own preference first, then
     * alphabetically so two methods sharing a `sort_order` never swap places
     * between page loads.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function ordered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }
}
