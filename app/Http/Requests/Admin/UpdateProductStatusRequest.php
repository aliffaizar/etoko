<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The quick toggles on the product listing.
 *
 * Exactly one of the two is sent, so a row can switch a product off or mark it
 * featured without reopening the whole form.
 */
class UpdateProductStatusRequest extends FormRequest
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
            'is_active' => ['required_without:is_featured', 'boolean'],
            'is_featured' => ['required_without:is_active', 'boolean'],
        ];
    }

    /**
     * Only the flags that were actually sent, so a missing one is left as it is
     * instead of being read as false.
     *
     * @return array<string, bool>
     */
    public function flags(): array
    {
        $flags = [];

        foreach (['is_active', 'is_featured'] as $flag) {
            if ($this->has($flag)) {
                $flags[$flag] = $this->boolean($flag);
            }
        }

        return $flags;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['is_active' => 'status aktif', 'is_featured' => 'produk unggulan'];
    }
}
