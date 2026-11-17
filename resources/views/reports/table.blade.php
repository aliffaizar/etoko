@php
    use App\Services\Reporting\ReportTable;
    use App\Support\Money;

    /** @var ReportTable $table */
    /** @var array<int, array<string, mixed>> $rows */

    $format = function (mixed $value, string $type): string {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($type) {
            ReportTable::MONEY => Money::format((int) $value),
            ReportTable::NUMBER => number_format((float) $value, 0, ',', '.'),
            ReportTable::PERCENT => number_format((float) $value * 100, 1, ',', '.').'%',
            default => (string) $value,
        };
    };
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $table->title }}</title>
    <style>
        @page { margin: 28px 30px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #1f2937; line-height: 1.4; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #6b7280; }
        .meta td { padding: 1px 12px 1px 0; }
        .data { margin-top: 14px; }
        .data th { background: #f3f4f6; text-align: left; padding: 5px 6px; border-bottom: 1px solid #d1d5db; font-size: 8.5px; }
        .data td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; }
        .data .num { text-align: right; white-space: nowrap; }
        .data tfoot td { font-weight: bold; border-top: 1px solid #9ca3af; border-bottom: none; }
        .empty { padding: 16px 6px; text-align: center; color: #6b7280; }
    </style>
</head>
<body>
    <h1>{{ $table->title }}</h1>
    <div class="muted">{{ $storeName }} &middot; dicetak {{ $printedAt->translatedFormat('j F Y H:i') }}</div>

    @if ($table->meta !== [])
        <table class="meta" style="margin-top: 8px; width: auto;">
            @foreach ($table->meta as $line)
                <tr>
                    <td class="muted">{{ $line['label'] }}</td>
                    <td>{{ $line['value'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <table class="data">
        <thead>
            <tr>
                @foreach ($table->columns as $column)
                    <th class="{{ $column['type'] === ReportTable::TEXT ? '' : 'num' }}">{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($table->columns as $key => $column)
                        <td class="{{ $column['type'] === ReportTable::TEXT ? '' : 'num' }}">{{ $format($row[$key] ?? null, $column['type']) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td class="empty" colspan="{{ count($table->columns) }}">Tidak ada data untuk filter ini.</td>
                </tr>
            @endforelse
        </tbody>
        @if ($table->totals !== null)
            <tfoot>
                <tr>
                    @foreach ($table->columns as $key => $column)
                        <td class="{{ $column['type'] === ReportTable::TEXT ? '' : 'num' }}">{{ $format($table->totals[$key] ?? null, $column['type']) }}</td>
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>
</body>
</html>
