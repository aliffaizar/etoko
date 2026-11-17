<?php

namespace App\Services\Reporting;

use App\Models\Branch;
use App\Models\Category;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * What a report is asked about: a period, and optionally a branch, a category
 * and the listing state of the screen (P2-E7-T4).
 *
 * Every report and every export reads the same object, so a screen and the
 * file exported from it can never disagree about which days "1–30 November"
 * covers. The period is whole days in the application time zone: `from` is
 * the start of its day and `until` the end of its day.
 *
 * `branchId` is only ever set for a central actor. A branch account is
 * already narrowed to its own branch by the branch global scope (BR-3), so
 * the request drops whatever it sent rather than filtering by it — a branch
 * admin naming another branch would otherwise get an empty report that
 * looks like "no sales".
 */
final readonly class ReportFilters
{
    public const GROUP_DAY = 'day';

    public const GROUP_WEEK = 'week';

    public const GROUP_MONTH = 'month';

    /** @var array<int, string> */
    public const GROUPS = [self::GROUP_DAY, self::GROUP_WEEK, self::GROUP_MONTH];

    /** Longest period a report may cover, in days. */
    public const MAX_DAYS = 731;

    /** Length of the period a report opens with when none is named. */
    public const DEFAULT_DAYS = 30;

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $until,
        public ?int $branchId = null,
        public ?int $categoryId = null,
        public string $group = self::GROUP_DAY,
        public string $search = '',
        public string $state = '',
        public string $sort = '',
        public string $direction = 'desc',
    ) {}

    /**
     * The filters from already-validated input — the request's query string,
     * or the copy of it a queued export carries.
     *
     * The branch is dropped for an actor bound to a branch: the branch scope
     * narrows them already, and honouring another branch's id would only
     * produce an empty report that reads as "no sales".
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input, User $actor): self
    {
        $default = self::lastDays();
        $from = filled($input['from'] ?? null)
            ? CarbonImmutable::parse((string) $input['from'])->startOfDay()
            : $default->from;
        $until = filled($input['until'] ?? null)
            ? CarbonImmutable::parse((string) $input['until'])->endOfDay()
            : ($from->greaterThan($default->until) ? $from->endOfDay() : $default->until);

        return new self(
            from: $from,
            until: $until,
            branchId: $actor->isCentral() && filled($input['branch'] ?? null) ? (int) $input['branch'] : null,
            categoryId: filled($input['category'] ?? null) ? (int) $input['category'] : null,
            group: in_array($input['group'] ?? null, self::GROUPS, strict: true) ? (string) $input['group'] : self::GROUP_DAY,
            search: trim((string) ($input['search'] ?? '')),
            state: (string) ($input['state'] ?? ''),
            sort: (string) ($input['sort'] ?? ''),
            direction: ($input['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
        );
    }

    /**
     * The last thirty days up to and including today.
     */
    public static function lastDays(int $days = self::DEFAULT_DAYS, ?CarbonImmutable $today = null): self
    {
        $today ??= CarbonImmutable::now();

        return new self(
            $today->subDays($days - 1)->startOfDay(),
            $today->endOfDay(),
        );
    }

    /**
     * The calendar month that contains the given day, up to the end of it.
     */
    public static function monthOf(CarbonImmutable $day): self
    {
        return new self($day->startOfMonth(), $day->endOfDay());
    }

    public function forBranch(?int $branchId): self
    {
        return new self(
            $this->from, $this->until, $branchId, $this->categoryId, $this->group,
            $this->search, $this->state, $this->sort, $this->direction,
        );
    }

    /**
     * The category ids a category filter covers: the category and everything
     * below it, because "Elektronik" has to include "Elektronik › Audio".
     *
     * @return array<int, int>|null Null when no category is asked for.
     */
    public function categoryIds(): ?array
    {
        if ($this->categoryId === null) {
            return null;
        }

        $category = Category::query()->find($this->categoryId);

        return $category === null ? [] : [$category->getKey(), ...$category->descendantIds()];
    }

    /**
     * Every bucket of the period, oldest first, so a chart has a point for a
     * day nobody bought anything — a gap in the line would read as "no data"
     * rather than "no sales".
     *
     * @return array<int, array{key: string, label: string}>
     */
    public function buckets(): array
    {
        $buckets = [];

        foreach (CarbonPeriod::create($this->from->startOfDay(), '1 day', $this->until->startOfDay()) as $day) {
            $key = $this->bucketKey(CarbonImmutable::instance($day));
            $buckets[$key] ??= ['key' => $key, 'label' => $this->bucketLabel($key)];
        }

        return array_values($buckets);
    }

    /**
     * The bucket a day belongs to under this report's grouping.
     *
     * Weeks start on Monday and are named after that Monday, so a week that
     * straddles the start of the period is still called by its real start.
     */
    public function bucketKey(CarbonImmutable $day): string
    {
        return match ($this->group) {
            self::GROUP_WEEK => $day->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
            self::GROUP_MONTH => $day->format('Y-m'),
            default => $day->toDateString(),
        };
    }

    private function bucketLabel(string $key): string
    {
        return match ($this->group) {
            self::GROUP_WEEK => 'Minggu '.CarbonImmutable::parse($key)->translatedFormat('j M Y'),
            self::GROUP_MONTH => CarbonImmutable::parse($key.'-01')->translatedFormat('F Y'),
            default => CarbonImmutable::parse($key)->translatedFormat('j M Y'),
        };
    }

    /**
     * The filters as the screens send them back to the query string.
     *
     * @return array{from: string, until: string, branch: string, category: string, group: string, search: string, state: string, sort: string, direction: string}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'until' => $this->until->toDateString(),
            'branch' => $this->branchId === null ? '' : (string) $this->branchId,
            'category' => $this->categoryId === null ? '' : (string) $this->categoryId,
            'group' => $this->group,
            'search' => $this->search,
            'state' => $this->state,
            'sort' => $this->sort,
            'direction' => $this->direction,
        ];
    }

    /**
     * The same filters as query parameters, without the empty ones — what a
     * queued export carries so it can rebuild the report it was asked for.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter($this->toArray(), fn (string $value): bool => $value !== '');
    }

    /**
     * Human-readable lines for the head of an exported file, so a printed
     * report says which period and which branch it is about.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function describe(bool $withPeriod = true): array
    {
        $lines = [];

        if ($withPeriod) {
            $lines[] = [
                'label' => 'Periode',
                'value' => $this->from->translatedFormat('j F Y').' – '.$this->until->translatedFormat('j F Y'),
            ];
        }

        $lines[] = [
            'label' => 'Cabang',
            'value' => $this->branchId === null
                ? 'Semua cabang yang dapat Anda akses'
                : (string) Branch::query()->whereKey($this->branchId)->value('name'),
        ];

        if ($this->categoryId !== null) {
            $lines[] = [
                'label' => 'Kategori',
                'value' => (string) Category::query()->whereKey($this->categoryId)->value('name'),
            ];
        }

        return $lines;
    }
}
