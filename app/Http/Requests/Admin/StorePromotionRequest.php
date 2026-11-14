<?php

namespace App\Http\Requests\Admin;

use App\Enums\PromotionType;
use App\Models\ProductVariant;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A promotion's fields and the products and branches it covers (P2-E8-T2).
 *
 * Create and update submit the same form, so update extends this class and
 * inherits its rules.
 *
 * The route carries `can:promotions.manage` and `PromotionPolicy` also demands
 * a central actor, so authorisation is not repeated here.
 */
class StorePromotionRequest extends FormRequest
{
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
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(PromotionType::class)],
            'value' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'priority' => ['required', 'integer', 'min:-1000', 'max:1000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'is_active' => ['boolean'],
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer', 'distinct', 'exists:products,id'],
            'branch_ids' => ['array'],
            'branch_ids.*' => ['integer', 'distinct', 'exists:branches,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'starts_at' => $this->input('starts_at') === '' ? null : $this->input('starts_at'),
            'ends_at' => $this->input('ends_at') === '' ? null : $this->input('ends_at'),
            'value' => $this->input('value') === '' ? null : $this->input('value'),
        ]);
    }

    /**
     * A percentage can take at most the whole price, and a period must end
     * after it starts.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->assertLeavesAPrice($validator);

                if ($this->filled('starts_at') && $this->filled('ends_at')
                    && $this->date('ends_at')->lessThanOrEqualTo($this->date('starts_at'))) {
                    $validator->errors()->add('ends_at', 'Waktu berakhir harus setelah waktu mulai.');
                }
            },
        ];
    }

    /**
     * A promotion may lower a price but never wipe it out (ADR-067): a
     * percentage stays under 100, and a fixed cut stays under the cheapest
     * option of every product it names. Both catch a mistyped value before it
     * gives goods away; `PromotionType::discountFor()` still caps the cut at
     * the price should a product's price later fall below it.
     */
    private function assertLeavesAPrice(Validator $validator): void
    {
        $value = (int) $this->input('value');

        if ($this->input('type') === PromotionType::Percentage->value) {
            if ($value >= 100) {
                $validator->errors()->add('value', 'Persentase promo harus di bawah 100 agar produk tidak menjadi gratis.');
            }

            return;
        }

        $variants = ProductVariant::query()
            ->whereIn('product_id', array_map('intval', (array) $this->input('product_ids', [])))
            ->where('is_active', true)
            ->with('product:id,name,base_price')
            ->get();

        foreach ($variants as $variant) {
            if ($value >= $variant->effectivePrice()) {
                $validator->errors()->add(
                    'value',
                    "Potongan promo tidak boleh sama dengan atau melebihi harga {$variant->product->name} (".Money::format($variant->effectivePrice()).').',
                );

                return;
            }
        }
    }

    /**
     * The shape `SavePromotion` takes.
     *
     * @return array{attributes: array<string, mixed>, product_ids: array<int, int>, branch_ids: array<int, int>}
     */
    public function promotionData(): array
    {
        $validated = $this->validated();

        return [
            'attributes' => [
                'name' => $validated['name'],
                'type' => $validated['type'],
                'value' => (int) $validated['value'],
                'priority' => (int) $validated['priority'],
                'starts_at' => $validated['starts_at'] ?? null,
                'ends_at' => $validated['ends_at'] ?? null,
                'is_active' => $this->boolean('is_active', true),
            ],
            'product_ids' => array_values(array_map('intval', $validated['product_ids'])),
            'branch_ids' => array_values(array_map('intval', $validated['branch_ids'] ?? [])),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama promo',
            'type' => 'jenis promo',
            'value' => 'nilai promo',
            'priority' => 'prioritas',
            'starts_at' => 'waktu mulai',
            'ends_at' => 'waktu berakhir',
            'product_ids' => 'produk',
            'branch_ids' => 'cabang',
        ];
    }
}
