<?php

namespace App\Http\Requests\Shop;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\ProductVariant;
use App\Scopes\BranchScope;
use App\Services\Branch\StorefrontBranch;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding a variant to the basket (P1-E6-T4).
 *
 * The id has to name something a shopper may actually buy, not merely a row in
 * `product_variants` (MED-02). `exists:product_variants,id` was the whole
 * check before, so a guessed id put an unreleased product — its name, its
 * photo and its price — on the guesser's own cart page. The sale itself was
 * never possible, because `PlaceOrder` asks these same questions again at
 * checkout and refuses, but a pre-launch price is not something to hand out.
 *
 * Two questions, and they are separate because their answers come from
 * different places:
 *
 * - **Is the variant on sale at all?** `ProductVariant::buyable()` — active
 *   variant, published product, active category — the same conditions
 *   checkout applies.
 * - **Does the chosen branch stock it?** `branch_products.is_available`, which
 *   depends on the branch the *visitor* picked (ADR-028), so it is read from
 *   `StorefrontBranch` rather than from any staff context.
 *
 * Both answer **422 on the field**, not a 404 from `findOrFail` further down:
 * this is a form being refused, and the cart screen shows the message against
 * the control the shopper used.
 */
class AddCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_variant_id.exists' => 'Produk itu tidak dijual.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('product_variant_id')) {
                return;
            }

            $variantId = (int) $this->input('product_variant_id');

            if (! ProductVariant::query()->buyable()->whereKey($variantId)->exists()) {
                $validator->errors()->add('product_variant_id', 'Produk itu tidak dijual.');

                return;
            }

            if ($this->switchedOffAtBranch($variantId)) {
                $validator->errors()->add(
                    'product_variant_id',
                    'Produk itu tidak dijual di cabang yang Anda pilih.',
                );
            }
        });
    }

    /**
     * Whether the visitor's branch has switched this variant's product off.
     *
     * Only deviations are stored, so a missing row means the branch sells it
     * (`BranchProduct::availabilityFor()`). Read across branch scope on
     * purpose: the branch here is the shopper's choice, and a shopper has no
     * branch context of their own (ADR-028).
     */
    private function switchedOffAtBranch(int $variantId): bool
    {
        $branch = app(StorefrontBranch::class)->current();

        if (! $branch instanceof Branch) {
            return false;
        }

        $productId = ProductVariant::query()->whereKey($variantId)->value('product_id');

        if ($productId === null) {
            return false;
        }

        return BranchProduct::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branch->getKey())
            ->where('product_id', $productId)
            ->unavailable()
            ->exists();
    }
}
