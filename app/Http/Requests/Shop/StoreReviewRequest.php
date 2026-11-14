<?php

namespace App\Http\Requests\Shop;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A customer's review of one order line (P2-E8-T3).
 */
class StoreReviewRequest extends FormRequest
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
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:120'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:5'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => $this->input('title') === '' ? null : $this->input('title'),
            'comment' => $this->input('comment') === '' ? null : $this->input('comment'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'rating' => 'bintang',
            'title' => 'judul ulasan',
            'comment' => 'isi ulasan',
            'photos' => 'foto',
            'photos.*' => 'foto',
        ];
    }
}
