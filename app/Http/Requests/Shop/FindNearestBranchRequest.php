<?php

namespace App\Http\Requests\Shop;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Where the shopper is, so the picker can offer the closest branch
 * (P1-E5-T2).
 *
 * The coordinates come from the browser's geolocation API, which the shopper
 * has to allow — so this endpoint never guesses a location, it only receives
 * one that was offered. Nothing is stored: the point is used to pick a branch
 * and then discarded, and only the branch id is remembered.
 */
class FindNearestBranchRequest extends FormRequest
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
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'latitude' => 'lintang',
            'longitude' => 'bujur',
        ];
    }

    public function latitude(): float
    {
        return (float) $this->validated('latitude');
    }

    public function longitude(): float
    {
        return (float) $this->validated('longitude');
    }
}
