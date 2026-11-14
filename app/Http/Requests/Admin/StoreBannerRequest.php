<?php

namespace App\Http\Requests\Admin;

use App\Enums\BannerPosition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A banner's fields and images (P2-E8-T1).
 *
 * Creating needs an image; editing does not, so the rule is per request and
 * `UpdateBannerRequest` relaxes it.
 */
class StoreBannerRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:120'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'link_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'position' => ['required', Rule::enum(BannerPosition::class)],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'is_active' => ['boolean'],
            'image' => $this->imageRules(),
            'mobile_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function imageRules(): array
    {
        return ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'branch_id' => $this->input('branch_id') === '' ? null : $this->input('branch_id'),
            'link_url' => $this->input('link_url') === '' ? null : $this->input('link_url'),
            'starts_at' => $this->input('starts_at') === '' ? null : $this->input('starts_at'),
            'ends_at' => $this->input('ends_at') === '' ? null : $this->input('ends_at'),
        ]);
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->filled('starts_at') && $this->filled('ends_at')
                    && $this->date('ends_at')->lessThanOrEqualTo($this->date('starts_at'))) {
                    $validator->errors()->add('ends_at', 'Waktu berakhir harus setelah waktu mulai.');
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function bannerAttributes(): array
    {
        $validated = $this->validated();

        return [
            'title' => $validated['title'],
            'subtitle' => $validated['subtitle'] ?? null,
            'link_url' => $validated['link_url'] ?? null,
            'position' => $validated['position'],
            'branch_id' => isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
            'sort_order' => (int) $validated['sort_order'],
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'is_active' => $this->boolean('is_active', true),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'judul',
            'link_url' => 'tautan',
            'position' => 'posisi',
            'branch_id' => 'cabang',
            'sort_order' => 'urutan',
            'starts_at' => 'waktu mulai',
            'ends_at' => 'waktu berakhir',
            'image' => 'gambar',
            'mobile_image' => 'gambar seluler',
        ];
    }
}
