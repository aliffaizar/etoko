<?php

namespace App\Actions\Product;

/**
 * What a CSV import did, or why it did nothing (P1-E3-T11).
 *
 * An import is all-or-nothing, so there are only two outcomes worth
 * reporting: it ran and these many products were created and updated, or it
 * refused and here is every row that is wrong. A half-applied import has no
 * representation here because it cannot happen.
 */
final class ProductImportResult
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  Parsed rows, pending application.
     * @param  array<int, array{line: int, messages: array<int, string>}>  $errors
     */
    private function __construct(
        public array $rows = [],
        public array $errors = [],
        public int $created = 0,
        public int $updated = 0,
        public bool $wasApplied = false,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function readyToApply(array $rows): self
    {
        return new self(rows: $rows);
    }

    /**
     * @param  array<int, array{line: int, messages: array<int, string>}>  $errors
     */
    public static function rejected(array $errors): self
    {
        return new self(errors: $errors);
    }

    public static function applied(int $created, int $updated): self
    {
        return new self(created: $created, updated: $updated, wasApplied: true);
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function totalChanged(): int
    {
        return $this->created + $this->updated;
    }

    /**
     * How the outcome reads to the person who uploaded the file.
     */
    public function summary(): string
    {
        if ($this->hasErrors()) {
            $rows = count($this->errors);

            return "Impor dibatalkan: {$rows} baris bermasalah. Tidak ada produk yang diubah.";
        }

        return "Impor selesai: {$this->created} produk baru, {$this->updated} produk diperbarui.";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'was_applied' => $this->wasApplied,
            'created' => $this->created,
            'updated' => $this->updated,
            'errors' => $this->errors,
            'summary' => $this->summary(),
        ];
    }
}
