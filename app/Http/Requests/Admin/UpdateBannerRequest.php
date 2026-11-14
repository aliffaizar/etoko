<?php

namespace App\Http\Requests\Admin;

/**
 * Editing keeps the picture the banner already has unless a new one is sent.
 */
class UpdateBannerRequest extends StoreBannerRequest
{
    /**
     * @return array<int, mixed>
     */
    protected function imageRules(): array
    {
        return ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'];
    }
}
