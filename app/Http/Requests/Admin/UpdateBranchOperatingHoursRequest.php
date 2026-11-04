<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates a branch's weekly opening hours (P1-E2-T4).
 *
 * All seven days are submitted together, because a branch always holds exactly
 * seven rows and a partial submission would leave the week inconsistent.
 */
class UpdateBranchOperatingHoursRequest extends FormRequest
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
            'hours' => ['required', 'array', 'size:7'],
            'hours.*.day_of_week' => ['required', 'integer', 'between:0,6', 'distinct'],
            'hours.*.is_closed' => ['boolean'],
            'hours.*.opens_at' => ['nullable', 'date_format:H:i'],
            'hours.*.closes_at' => ['nullable', 'date_format:H:i'],
        ];
    }

    /**
     * A day that is open needs both times, and has to close after it opens.
     * Expressed here rather than as a rule string because the requirement
     * depends on the sibling `is_closed` flag.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            /** @var array<int, array<string, mixed>> $hours */
            $hours = (array) $this->input('hours', []);

            foreach ($hours as $index => $hour) {
                if ((bool) ($hour['is_closed'] ?? false)) {
                    continue;
                }

                $opensAt = (string) ($hour['opens_at'] ?? '');
                $closesAt = (string) ($hour['closes_at'] ?? '');

                if ($opensAt === '' || $closesAt === '') {
                    $validator->errors()->add(
                        "hours.{$index}.opens_at",
                        'Isi jam buka dan jam tutup, atau tandai hari ini sebagai tutup.',
                    );

                    continue;
                }

                if ($closesAt <= $opensAt) {
                    $validator->errors()->add(
                        "hours.{$index}.closes_at",
                        'Jam tutup harus lebih akhir dari jam buka.',
                    );
                }
            }
        }];
    }

    /**
     * @return array<int, array{day_of_week: int, opens_at: string|null, closes_at: string|null, is_closed: bool}>
     */
    public function operatingHours(): array
    {
        /** @var array<int, array{day_of_week: int, opens_at?: string|null, closes_at?: string|null, is_closed?: bool}> $hours */
        $hours = $this->validated('hours');

        return array_map(fn (array $hour): array => [
            'day_of_week' => (int) $hour['day_of_week'],
            'opens_at' => $hour['opens_at'] ?? null,
            'closes_at' => $hour['closes_at'] ?? null,
            'is_closed' => (bool) ($hour['is_closed'] ?? false),
        ], array_values($hours));
    }
}
