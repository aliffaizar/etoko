<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A branch's own price for one variant (P1-E3-T10).
 *
 * Prices are whole rupiah integers (ADR-008, BR-10). The schedule is optional
 * on both ends: no start means "already", no end means "until switched off".
 *
 * Clearing a branch price is a delete on its own route, not a zero here — a
 * price of 0 means the branch gives the variant away, and that must stay
 * sayable.
 */
class StoreBranchProductPriceRequest extends FormRequest
{
    /** The largest rupiah amount a price field accepts, to keep typos out. */
    public const MAX_PRICE = 999_999_999_999;

    /**
     * The route carries `can:branch_prices.manage` and the controller asks
     * `BranchPolicy::managePrices`, which additionally demands a central
     * actor.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'price' => ['required', 'integer', 'min:0', 'max:'.self::MAX_PRICE],
            'compare_at_price' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_PRICE],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->requireCompareAtPriceAbovePrice($validator),
            fn (Validator $validator) => $this->requireEndAfterStart($validator),
        ];
    }

    /**
     * A strikethrough price that is not actually higher advertises a discount
     * that does not exist.
     */
    private function requireCompareAtPriceAbovePrice(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['price', 'compare_at_price'])) {
            return;
        }

        $compareAt = $this->input('compare_at_price');

        if ($compareAt === null || $compareAt === '') {
            return;
        }

        if ((int) $compareAt > $this->integer('price')) {
            return;
        }

        $validator->errors()->add(
            'compare_at_price',
            'Harga sebelum diskon harus lebih tinggi dari harga cabang, atau dibiarkan kosong.',
        );
    }

    /**
     * A window that ends before it starts would never apply, and would do so
     * silently — the row would simply never be in force.
     */
    private function requireEndAfterStart(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['starts_at', 'ends_at'])) {
            return;
        }

        $startsAt = $this->date('starts_at');
        $endsAt = $this->date('ends_at');

        if ($startsAt === null || $endsAt === null || $endsAt->isAfter($startsAt)) {
            return;
        }

        $validator->errors()->add('ends_at', 'Tanggal berakhir harus setelah tanggal mulai.');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'price' => 'harga cabang',
            'compare_at_price' => 'harga sebelum diskon',
            'starts_at' => 'tanggal mulai',
            'ends_at' => 'tanggal berakhir',
            'is_active' => 'status aktif',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'price.required' => 'Harga cabang wajib diisi. Hapus harga khusus bila cabang ini memakai harga katalog.',
            'price.integer' => 'Harga cabang harus berupa angka rupiah bulat, tanpa titik atau koma.',
        ];
    }
}
