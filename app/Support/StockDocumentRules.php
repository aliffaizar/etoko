<?php

namespace App\Support;

/**
 * The validation rules every stock document's lines share (P1-E4-T5, T6).
 *
 * One source for both document kinds, the way `CatalogRules` is one source for
 * the product form and the CSV import. The two line shapes are identical, and
 * a second copy of these rules is a second chance for one of them to drift —
 * which here would mean one screen accepting a quantity the other refuses.
 */
final class StockDocumentRules
{
    /** Largest quantity accepted on one line, to keep a slipped keystroke out. */
    public const MAX_QUANTITY = 1_000_000;

    /** Largest unit cost accepted, in whole rupiah (ADR-008). */
    public const MAX_UNIT_COST = 999_999_999_999;

    /** Lines one document may carry. */
    public const MAX_ITEMS = 200;

    /**
     * Rules for the `items` array of a goods document.
     *
     * `distinct` on the variant id is what refuses the same variant twice: the
     * unique index would catch it anyway, but as a 500 rather than as a
     * message next to the duplicate.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function items(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*.product_variant_id' => ['required', 'integer', 'distinct', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_QUANTITY],
            'items.*.unit_cost' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_UNIT_COST],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function itemAttributes(): array
    {
        return [
            'items' => 'baris barang',
            'items.*.product_variant_id' => 'varian',
            'items.*.quantity' => 'jumlah',
            'items.*.unit_cost' => 'harga satuan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function itemMessages(): array
    {
        return [
            'items.required' => 'Tambahkan minimal satu baris barang.',
            'items.*.product_variant_id.distinct' => 'Varian ini sudah ada di baris lain. Gabungkan jumlahnya menjadi satu baris.',
            'items.*.quantity.min' => 'Jumlah harus minimal 1. Arah masuk atau keluar ditentukan jenis dokumennya.',
        ];
    }
}
