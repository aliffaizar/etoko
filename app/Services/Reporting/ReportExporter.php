<?php

namespace App\Services\Reporting;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * Writes a `ReportTable` as an Excel workbook or a PDF (P2-E7-T9).
 *
 * Excel is written with OpenSpout, row by row from the table's iterable, so a
 * large report never sits in memory whole. Amounts go in as **numbers** with a
 * rupiah format rather than as formatted text, so whoever opens the file can
 * still add them up.
 *
 * PDF goes through dompdf, the same as the invoice (ADR-059), from a plain
 * HTML table with CSS 2.1 — landscape A4 because reports are wide.
 */
final class ReportExporter
{
    public const XLSX = 'xlsx';

    public const PDF = 'pdf';

    /** @var array<int, string> */
    public const FORMATS = [self::XLSX, self::PDF];

    /** Rupiah, whole amounts, thousands separated. */
    private const MONEY_FORMAT = '"Rp" #,##0';

    private const NUMBER_FORMAT = '#,##0';

    private const PERCENT_FORMAT = '0.0%';

    /**
     * Write the table to a file on the given disk and return its path there.
     */
    public function store(ReportTable $table, string $format, string $disk, string $path): string
    {
        $local = $this->writeTemporary($table, $format);

        try {
            $stream = fopen($local, 'rb');

            if ($stream === false) {
                throw new RuntimeException("The export at {$local} could not be read back.");
            }

            Storage::disk($disk)->writeStream($path, $stream);
            fclose($stream);
        } finally {
            @unlink($local);
        }

        return $path;
    }

    /**
     * Write the table to a temporary local file and return the file's path.
     * The caller owns the file and must remove it.
     */
    public function writeTemporary(ReportTable $table, string $format): string
    {
        $path = storage_path('app/private/tmp/'.Str::uuid().'.'.$format);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, recursive: true);
        }

        $format === self::PDF
            ? file_put_contents($path, $this->pdf($table))
            : $this->xlsx($table, $path);

        return $path;
    }

    /**
     * The file name an export is offered under, e.g.
     * `laporan-penjualan-2026-11-17.xlsx`.
     */
    public function fileName(ReportTable $table, string $format): string
    {
        return Str::slug($table->title).'-'.now()->format('Y-m-d-His').'.'.$format;
    }

    private function xlsx(ReportTable $table, string $path): void
    {
        $options = new Options(DEFAULT_COLUMN_WIDTH: 18);

        $writer = new Writer($options);
        $writer->openToFile($path);

        $bold = new Style(fontBold: true);

        $writer->addRow(new Row([Cell::fromValue($table->title, new Style(fontBold: true, fontSize: 14))]));

        foreach ($table->meta as $line) {
            $writer->addRow(new Row([Cell::fromValue($line['label']), Cell::fromValue($line['value'])]));
        }

        $writer->addRow(new Row([]));
        $writer->addRow(new Row(array_values(array_map(
            fn (array $column): Cell => Cell::fromValue($column['label'], $bold),
            $table->columns,
        ))));

        foreach ($table->rows as $row) {
            $writer->addRow($this->xlsxRow($table, $row));
        }

        if ($table->totals !== null) {
            $writer->addRow($this->xlsxRow($table, $table->totals, bold: true));
        }

        $writer->close();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function xlsxRow(ReportTable $table, array $row, bool $bold = false): Row
    {
        $cells = [];

        foreach ($table->columns as $key => $column) {
            $value = $row[$key] ?? null;
            $format = match ($column['type']) {
                ReportTable::MONEY => self::MONEY_FORMAT,
                ReportTable::NUMBER => self::NUMBER_FORMAT,
                ReportTable::PERCENT => self::PERCENT_FORMAT,
                default => null,
            };

            $style = new Style(fontBold: $bold, format: $format);

            $cells[] = Cell::fromValue(
                is_scalar($value) || $value === null ? $value : (string) json_encode($value),
                $style,
            );
        }

        return new Row($cells);
    }

    private function pdf(ReportTable $table): string
    {
        $rows = [];

        foreach ($table->rows as $row) {
            $rows[] = $row;
        }

        return Pdf::loadView('reports.table', [
            'storeName' => (string) config('app.name'),
            'table' => $table,
            'rows' => $rows,
            'printedAt' => now(),
        ])
            ->setPaper('a4', 'landscape')
            ->setOption('isFontSubsettingEnabled', true)
            ->output();
    }
}
