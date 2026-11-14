<?php

namespace App\Concerns;

use App\Enums\VoucherAppliesTo;
use App\Enums\VoucherChannel;
use App\Enums\VoucherType;
use App\Models\Voucher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared validation for a voucher's fields (P2-E5-T2).
 *
 * Create and edit submit the same form. The rules that depend on each other —
 * a percentage between 1 and 100, a fixed amount above zero, a category or
 * product list when the voucher is narrowed to one, a quota no lower than
 * what has already been redeemed — are in `after()` so each lands on the
 * field the admin has to change.
 *
 * @phpstan-require-extends FormRequest
 */
trait ValidatesVoucherDetails
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function voucherDetailRules(?Voucher $voucher = null): array
    {
        return [
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('vouchers', 'code')->ignore($voucher?->getKey()),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['required', Rule::enum(VoucherType::class)],
            'value' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'max_discount' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'min_order_amount' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'quota' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'per_user_limit' => ['required', 'integer', 'min:0', 'max:1000'],
            'applies_to' => ['required', Rule::enum(VoucherAppliesTo::class)],
            'channel' => ['required', Rule::enum(VoucherChannel::class)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'is_active' => ['boolean'],
            'branch_ids' => ['array'],
            'branch_ids.*' => ['integer', 'distinct', 'exists:branches,id'],
            'category_ids' => ['array'],
            'category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
            'product_ids' => ['array'],
            'product_ids.*' => ['integer', 'distinct', 'exists:products,id'],
        ];
    }

    /**
     * Upper-case the code before uniqueness is checked, so `hemat10` collides
     * with `HEMAT10`; blank number fields arrive as null.
     */
    protected function normalizeVoucherInput(): void
    {
        $this->merge([
            'code' => Voucher::normalizeCode((string) $this->input('code', '')),
            'min_order_amount' => $this->input('min_order_amount') === '' ? null : $this->input('min_order_amount'),
            'value' => $this->input('value') === '' ? null : $this->input('value'),
            'max_discount' => $this->input('max_discount') === '' ? null : $this->input('max_discount'),
            'quota' => $this->input('quota') === '' ? null : $this->input('quota'),
            'starts_at' => $this->input('starts_at') === '' ? null : $this->input('starts_at'),
            'ends_at' => $this->input('ends_at') === '' ? null : $this->input('ends_at'),
        ]);
    }

    protected function validateVoucherCombination(Validator $validator, ?Voucher $voucher = null): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $type = VoucherType::from((string) $this->input('type'));
        $value = (int) $this->input('value', 0);

        if ($type === VoucherType::Percentage && ($value < 1 || $value > 100)) {
            $validator->errors()->add('value', 'Persentase diskon harus antara 1 dan 100.');
        }

        if ($type === VoucherType::Fixed && $value < 1) {
            $validator->errors()->add('value', 'Isi besar potongan dalam rupiah.');
        }

        $appliesTo = VoucherAppliesTo::from((string) $this->input('applies_to'));

        if ($appliesTo === VoucherAppliesTo::Category && $this->input('category_ids', []) === []) {
            $validator->errors()->add('category_ids', 'Pilih minimal satu kategori.');
        }

        if ($appliesTo === VoucherAppliesTo::Product && $this->input('product_ids', []) === []) {
            $validator->errors()->add('product_ids', 'Pilih minimal satu produk.');
        }

        $startsAt = $this->date('starts_at');
        $endsAt = $this->date('ends_at');

        if ($startsAt !== null && $endsAt !== null && $endsAt->lessThanOrEqualTo($startsAt)) {
            $validator->errors()->add('ends_at', 'Waktu berakhir harus setelah waktu mulai berlaku.');
        }

        $quota = $this->input('quota');

        if ($voucher instanceof Voucher && $quota !== null && (int) $quota < $voucher->used_count) {
            $validator->errors()->add('quota', "Kuota tidak boleh lebih kecil dari yang sudah terpakai ({$voucher->used_count}).");
        }
    }

    /**
     * The validated fields, shaped for `SaveVoucher`.
     *
     * The list a voucher is not narrowed to is emptied, and `value` is zero
     * for free shipping (its ceiling is `max_discount`), so a voucher never
     * carries a rule its screen does not show.
     *
     * @return array{attributes: array<string, mixed>, branch_ids: array<int, int>, category_ids: array<int, int>, product_ids: array<int, int>}
     */
    public function voucherData(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();
        $type = VoucherType::from((string) $validated['type']);
        $appliesTo = VoucherAppliesTo::from((string) $validated['applies_to']);

        $ids = fn (string $key): array => array_values(array_map('intval', (array) ($validated[$key] ?? [])));

        return [
            'attributes' => [
                'code' => $validated['code'],
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'type' => $type,
                'value' => $type === VoucherType::FreeShipping ? 0 : (int) ($validated['value'] ?? 0),
                'max_discount' => $type === VoucherType::Fixed ? null : ($validated['max_discount'] ?? null),
                'min_order_amount' => (int) ($validated['min_order_amount'] ?? 0),
                'quota' => $validated['quota'] ?? null,
                'per_user_limit' => (int) $validated['per_user_limit'],
                'applies_to' => $appliesTo,
                'channel' => VoucherChannel::from((string) $validated['channel']),
                'starts_at' => $validated['starts_at'] ?? null,
                'ends_at' => $validated['ends_at'] ?? null,
                'is_active' => (bool) ($validated['is_active'] ?? false),
            ],
            'branch_ids' => $ids('branch_ids'),
            'category_ids' => $appliesTo === VoucherAppliesTo::Category ? $ids('category_ids') : [],
            'product_ids' => $appliesTo === VoucherAppliesTo::Product ? $ids('product_ids') : [],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function voucherDetailAttributes(): array
    {
        return [
            'code' => 'kode voucher',
            'name' => 'nama voucher',
            'description' => 'deskripsi',
            'type' => 'jenis voucher',
            'value' => 'nilai',
            'max_discount' => 'potongan maksimal',
            'min_order_amount' => 'minimal belanja',
            'quota' => 'kuota',
            'per_user_limit' => 'batas per pelanggan',
            'applies_to' => 'berlaku untuk',
            'channel' => 'kanal',
            'starts_at' => 'mulai berlaku',
            'ends_at' => 'berakhir',
            'is_active' => 'status aktif',
            'branch_ids' => 'cabang',
            'category_ids' => 'kategori',
            'product_ids' => 'produk',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function voucherDetailMessages(): array
    {
        return [
            'code.regex' => 'Kode voucher hanya boleh berisi huruf, angka, tanda hubung, dan garis bawah.',
        ];
    }
}
