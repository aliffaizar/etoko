<?php

namespace App\Actions\Product;

use App\Models\Product;
use App\Support\ProductCsvSchema;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Writes the catalogue out as CSV (P1-E3-T11).
 *
 * Streamed in chunks rather than built in memory: a catalogue is exactly the
 * kind of thing that is small during development and large in production, and
 * an export that works on 50 products and dies on 50 000 is worse than none.
 *
 * The columns are `ProductCsvSchema`'s, so the file that comes out goes back
 * into the import unchanged — which is how a catalogue is normally edited in
 * bulk: export, fix in a spreadsheet, import.
 *
 * A UTF-8 BOM is written first so Excel opens the file as UTF-8 instead of
 * mangling every "Ã©" and rupiah figure; the import strips it again.
 */
final class ExportProducts
{
    /** Products loaded per query while streaming. */
    private const CHUNK_SIZE = 500;

    /**
     * @param  Builder<Product>  $query  The listing's own query, so an export honours the filters on screen.
     */
    public function handle(Builder $query, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\u{FEFF}");
            fputcsv($handle, ProductCsvSchema::COLUMNS);

            $query->with(['category:id,slug', 'defaultVariant'])
                ->chunkById(self::CHUNK_SIZE, function ($products) use ($handle): void {
                    foreach ($products as $product) {
                        fputcsv($handle, array_values(ProductCsvSchema::rowFor($product)));
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * The empty template: headers and one example row, for a catalogue that is
     * being loaded for the first time and has nothing to export yet.
     */
    public function template(string $filename): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\u{FEFF}");
            fputcsv($handle, ProductCsvSchema::COLUMNS);
            fputcsv($handle, array_values(ProductCsvSchema::exampleRow()));

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
