<?php

namespace App\Actions\Product;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\ProductCsvSchema;
use Illuminate\Support\Facades\DB;
use SplFileObject;

/**
 * Loads products from a CSV file (P1-E3-T11).
 *
 * Nothing is written unless every row is valid. A spreadsheet of 300 products
 * with a typo in row 180 would otherwise leave the catalogue half-loaded, and
 * nobody could tell which half — so the file is read and validated in full
 * first, and the import runs in one transaction afterwards.
 *
 * `sku` is the business key: a row whose SKU already belongs to a variant
 * updates that variant's product, a new SKU creates one. That makes the import
 * re-runnable, which is what makes fixing a rejected file practical — correct
 * the cells it complained about and import the same file again.
 *
 * Both paths go through `CreateProduct` and `UpdateProduct`, so an imported
 * product is indistinguishable from one typed into the panel: it gets its
 * default variant (ADR-020), its slug through the observer (P1-E3-T8), and its
 * audit entry (BR-9).
 */
final class ImportProducts
{
    public function __construct(
        private CreateProduct $createProduct,
        private UpdateProduct $updateProduct,
    ) {}

    public function handle(string $path): ProductImportResult
    {
        $parsed = $this->readAndValidate($path);

        if ($parsed->hasErrors()) {
            return $parsed;
        }

        return DB::transaction(function () use ($parsed): ProductImportResult {
            $created = 0;
            $updated = 0;

            foreach ($parsed->rows as $attributes) {
                $existing = ProductVariant::withTrashed()
                    ->where('sku', $attributes['sku'])
                    ->first();

                if ($existing instanceof ProductVariant) {
                    $product = Product::withTrashed()->findOrFail($existing->product_id);
                    $this->updateProduct->handle($product, $attributes);
                    $updated++;

                    continue;
                }

                $this->createProduct->handle($attributes);
                $created++;
            }

            return ProductImportResult::applied($created, $updated);
        });
    }

    /**
     * Reads the file and validates every row, collecting the problems rather
     * than stopping at the first.
     *
     * A duplicate SKU *within the file* is caught here too: the database would
     * accept the second row as an update of the first, silently keeping only
     * the last of the two.
     */
    private function readAndValidate(string $path): ProductImportResult
    {
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);

        $header = null;
        $rows = [];
        $errors = [];
        $seenSkus = [];
        $lineNumber = 0;

        foreach ($file as $raw) {
            $lineNumber++;

            if (! is_array($raw) || $raw === [null]) {
                continue;
            }

            if ($header === null) {
                $header = $this->normaliseHeader($raw);
                $missing = array_diff(ProductCsvSchema::REQUIRED_COLUMNS, $header);

                if ($missing !== []) {
                    return ProductImportResult::rejected([
                        ['line' => $lineNumber, 'messages' => [
                            'Kolom wajib tidak ditemukan: '.implode(', ', $missing).'.',
                        ]],
                    ]);
                }

                continue;
            }

            if (count($rows) >= ProductCsvSchema::MAX_ROWS) {
                $errors[] = ['line' => $lineNumber, 'messages' => [
                    'Berkas melebihi '.ProductCsvSchema::MAX_ROWS.' baris. Bagi menjadi beberapa berkas.',
                ]];

                break;
            }

            $row = $this->combine($header, $raw);
            $parsed = ProductCsvSchema::parseRow($row);

            if ($parsed['errors'] !== []) {
                $errors[] = ['line' => $lineNumber, 'messages' => $parsed['errors']];

                continue;
            }

            /** @var array<string, mixed> $attributes */
            $attributes = $parsed['attributes'];
            $sku = (string) $attributes['sku'];

            if (isset($seenSkus[$sku])) {
                $errors[] = ['line' => $lineNumber, 'messages' => [
                    "SKU {$sku} sudah dipakai di baris {$seenSkus[$sku]} pada berkas yang sama.",
                ]];

                continue;
            }

            $seenSkus[$sku] = $lineNumber;
            $rows[] = $attributes;
        }

        if ($header === null) {
            return ProductImportResult::rejected([
                ['line' => 1, 'messages' => ['Berkas kosong atau tidak punya baris header.']],
            ]);
        }

        if ($errors !== []) {
            return ProductImportResult::rejected($errors);
        }

        if ($rows === []) {
            return ProductImportResult::rejected([
                ['line' => 1, 'messages' => ['Tidak ada baris data setelah header.']],
            ]);
        }

        return ProductImportResult::readyToApply($rows);
    }

    /**
     * Lower-cases the header and strips a UTF-8 BOM, which Excel writes on the
     * first cell and which would otherwise make `sku` unrecognisable.
     *
     * @param  array<int, string|null>  $raw
     * @return array<int, string>
     */
    private function normaliseHeader(array $raw): array
    {
        return array_map(
            fn (?string $cell): string => mb_strtolower(trim(str_replace("\u{FEFF}", '', (string) $cell))),
            $raw,
        );
    }

    /**
     * Pairs a data row with the header, tolerating a row that is short or has
     * trailing cells — a spreadsheet export is rarely exactly rectangular.
     *
     * @param  array<int, string>  $header
     * @param  array<int, string|null>  $raw
     * @return array<string, string>
     */
    private function combine(array $header, array $raw): array
    {
        $row = [];

        foreach ($header as $index => $column) {
            $row[$column] = (string) ($raw[$index] ?? '');
        }

        return $row;
    }
}
