<?php

namespace App\Services\Reporting;

/**
 * A report as rows and columns, the shape both export formats are written
 * from (P2-E7-T9).
 *
 * One description for Excel and PDF, so the two files exported from the same
 * screen hold the same figures under the same headings. Rows are an iterable
 * rather than an array: a large report is streamed into the spreadsheet from a
 * lazy query instead of being held in memory whole.
 *
 * Column types tell the writers how to show a value — `money` is a whole
 * rupiah amount, `number` a count, `percent` a fraction between 0 and 1,
 * `text` anything else.
 */
final readonly class ReportTable
{
    public const TEXT = 'text';

    public const NUMBER = 'number';

    public const MONEY = 'money';

    public const PERCENT = 'percent';

    /**
     * @param  array<string, array{label: string, type: string}>  $columns  Keyed by the row key they read.
     * @param  iterable<int, array<string, scalar|null>>  $rows
     * @param  array<int, array{label: string, value: string}>  $meta  Lines printed above the table.
     * @param  array<string, scalar|null>|null  $totals  A closing row, keyed like the columns.
     */
    public function __construct(
        public string $title,
        public array $columns,
        public iterable $rows,
        public array $meta = [],
        public ?array $totals = null,
    ) {}
}
