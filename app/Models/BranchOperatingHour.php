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

    /**
     * Opening time as `HH:MM`, the shape an `<input type="time">` expects.
     */
    public function opensAtTime(): ?string
    {
        return self::displayTime($this->opens_at);
    }

    public function closesAtTime(): ?string
    {
        return self::displayTime($this->closes_at);
    }

    /**
     * Trim a stored time to `HH:MM`.
     *
     * MySQL hands back `08:00:00` while SQLite hands back whatever was written,
     * so the shape is settled here instead of at every read site.
     */
    public static function displayTime(?string $value): ?string
    {
        return $value === null || $value === '' ? null : mb_substr($value, 0, 5);
    }

    /**
     * Widen `HH:MM` to the `HH:MM:SS` a TIME column stores, so a value written
     * through SQLite reads back the same way it does through MySQL.
     */
    public static function normalizeTime(?string $value): ?string
    {
        $display = self::displayTime($value);

        return $display === null ? null : $display.':00';
    }
}
