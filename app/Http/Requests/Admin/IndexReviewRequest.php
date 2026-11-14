<?php

namespace App\Http\Requests\Admin;

use App\Enums\ReviewStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexReviewRequest extends FormRequest
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
            'status' => ['nullable', Rule::enum(ReviewStatus::class)],
        ];
    }

    public function status(): ?ReviewStatus
    {
        return $this->filled('status') ? ReviewStatus::from((string) $this->input('status')) : null;
    }
}
