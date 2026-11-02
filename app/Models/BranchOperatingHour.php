<?php

namespace App\Models;

use App\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $branch_id
 * @property int $day_of_week
 * @property string|null $opens_at
 * @property string|null $closes_at
 * @property bool $is_closed
 */
#[Fillable(['branch_id', 'day_of_week', 'opens_at', 'closes_at', 'is_closed'])]
class BranchOperatingHour extends Model
{
    use BelongsToBranch;

    /**
     * Indonesian day labels indexed the same way as `day_of_week`.
     *
     * @var array<int, string>
     */
    public const DAY_LABELS = [
        0 => 'Minggu',
        1 => 'Senin',
        2 => 'Selasa',
        3 => 'Rabu',
        4 => 'Kamis',
        5 => 'Jumat',
        6 => 'Sabtu',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'is_closed' => 'boolean',
        ];
    }

    public function dayLabel(): string
    {
        return self::DAY_LABELS[$this->day_of_week];
    }
}
