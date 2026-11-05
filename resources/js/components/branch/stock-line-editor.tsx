import { router } from '@inertiajs/react';
import { Plus, Search, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatNumber, formatRupiah } from '@/lib/format';
import type { StockLineDraft, StockVariantOption } from '@/types';

const SEARCH_DEBOUNCE_MS = 300;

export type StockLineEditorProps = {
    lines: StockLineDraft[];
    onChange: (lines: StockLineDraft[]) => void;
    /** Matches for what has been typed, reloaded from the server. */
    variantOptions: StockVariantOption[];
    /** Whether lines may still be changed; false once the document is posted. */
    isEditable: boolean;
    /** Shows each variant's available figure, which only matters for goods out. */
    showAvailability?: boolean;
    /** Whether lines carry a unit cost. */
    showUnitCost?: boolean;
    /** Per-line validation errors, keyed as the server sends them. */
    errors?: Record<string, string>;
};

/**
 * The lines of a stock document, while they are being typed (P1-E4-T5, T6).
 *
 * Shared by goods in and goods out, because the two differ only in what the
 * columns are for: a goods-in line is priced, a goods-out line is checked
 * against what is on the shelf. Both are "pick a variant, say how many".
 *
 * The variant picker searches on the server through an Inertia partial reload
 * of `variantOptions`, so a catalogue of thousands never has to reach the
 * browser. Each match shows the warehouse's current figure, because somebody
 * typing a goods-out quantity needs to know what is there *before* they type
 * it — the alternative is finding out when posting fails.
 *
 * A variant already on the document is offered as disabled rather than hidden:
 * "it is already on the list" is a more useful answer than the row quietly not
 * being there.
 */
export function StockLineEditor({
    lines,
    onChange,
    variantOptions,
    isEditable,
    showAvailability = false,
    showUnitCost = true,
    errors = {},
}: StockLineEditorProps) {
    const [search, setSearch] = useState('');
    const [isSearching, setIsSearching] = useState(false);

    useEffect(() => {
        const timeout = setTimeout(() => {
            router.reload({
                only: ['variantOptions'],
                data: { variant_search: search || undefined },
                onStart: () => setIsSearching(true),
                onFinish: () => setIsSearching(false),
            });
        }, SEARCH_DEBOUNCE_MS);

        return () => clearTimeout(timeout);
    }, [search]);

    const addLine = (option: StockVariantOption): void => {
        if (lines.some((line) => line.product_variant_id === option.value)) {
            return;
        }

        onChange([
            ...lines,
            {
                product_variant_id: option.value,
                sku: option.sku,
                display_name: option.label,
                product_name: option.product_name,
                quantity: 1,
                unit_cost: null,
                available: option.available,
            },
        ]);
    };

    const updateLine = (
        index: number,
        patch: Partial<StockLineDraft>,
    ): void => {
        onChange(
            lines.map((line, position) =>
                position === index ? { ...line, ...patch } : line,
            ),
        );
    };

    const removeLine = (index: number): void => {
        onChange(lines.filter((_, position) => position !== index));
    };

    return (
        <div className="flex flex-col gap-4">
            {isEditable && (
                <div className="flex flex-col gap-3 rounded-lg border p-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="variant-search">Tambah varian</Label>
                        <div className="relative">
                            <Search
                                aria-hidden
                                className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <Input
                                id="variant-search"
                                type="search"
                                className="pl-8"
                                value={search}
                                placeholder="Cari SKU, barcode, atau nama produk…"
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                            />
                        </div>
                    </div>

                    <ul
                        className="flex max-h-56 flex-col divide-y overflow-y-auto rounded-md border"
                        aria-busy={isSearching}
                    >
                        {variantOptions.length === 0 ? (
                            <li className="p-3 text-sm text-muted-foreground">
                                Tidak ada varian yang cocok.
                            </li>
                        ) : (
                            variantOptions.map((option) => {
                                const isOnDocument = lines.some(
                                    (line) =>
                                        line.product_variant_id ===
                                        option.value,
                                );

                                return (
                                    <li
                                        key={option.value}
                                        className="flex items-center justify-between gap-3 p-2"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate text-sm">
                                                {option.product_name}
                                            </p>
                                            <p className="truncate text-xs text-muted-foreground">
                                                <span className="font-mono">
                                                    {option.sku}
                                                </span>
                                                {showAvailability
                                                    ? ` · tersedia ${formatNumber(option.available)}`
                                                    : ` · di gudang ${formatNumber(option.on_hand)}`}
                                            </p>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            disabled={isOnDocument}
                                            onClick={() => addLine(option)}
                                        >
                                            <Plus aria-hidden />
                                            {isOnDocument
                                                ? 'Sudah ada'
                                                : 'Tambah'}
                                        </Button>
                                    </li>
                                );
                            })
                        )}
                    </ul>
                </div>
            )}

            {errors.items && (
                <p className="text-sm text-destructive">{errors.items}</p>
            )}

            {lines.length === 0 ? (
                <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                    Belum ada baris barang. Cari varian di atas untuk
                    menambahkannya.
                </p>
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Varian</TableHead>
                                <TableHead className="text-right">
                                    Jumlah
                                </TableHead>
                                {showUnitCost && (
                                    <TableHead className="text-right">
                                        Harga satuan
                                    </TableHead>
                                )}
                                {isEditable && (
                                    <TableHead className="text-right">
                                        <span className="sr-only">Aksi</span>
                                    </TableHead>
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {lines.map((line, index) => (
                                <TableRow key={line.product_variant_id}>
                                    <TableCell>
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-medium">
                                                {line.product_name}
                                            </p>
                                            <p className="truncate text-xs text-muted-foreground">
                                                <span className="font-mono">
                                                    {line.sku}
                                                </span>
                                                {showAvailability &&
                                                line.available !== null
                                                    ? ` · tersedia ${formatNumber(line.available)}`
                                                    : ''}
                                            </p>
                                            {errors[
                                                `items.${index}.quantity`
                                            ] && (
                                                <p className="text-xs text-destructive">
                                                    {
                                                        errors[
                                                            `items.${index}.quantity`
                                                        ]
                                                    }
                                                </p>
                                            )}
                                            {errors[
                                                `items.${index}.product_variant_id`
                                            ] && (
                                                <p className="text-xs text-destructive">
                                                    {
                                                        errors[
                                                            `items.${index}.product_variant_id`
                                                        ]
                                                    }
                                                </p>
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {isEditable ? (
                                            <Input
                                                type="number"
                                                min={1}
                                                className="ml-auto w-24 text-right"
                                                aria-label={`Jumlah ${line.sku}`}
                                                value={line.quantity}
                                                onChange={(event) =>
                                                    updateLine(index, {
                                                        quantity:
                                                            Number(
                                                                event.target
                                                                    .value,
                                                            ) || 0,
                                                    })
                                                }
                                            />
                                        ) : (
                                            <span className="text-sm tabular-nums">
                                                {formatNumber(line.quantity)}
                                            </span>
                                        )}
                                    </TableCell>
                                    {showUnitCost && (
                                        <TableCell className="text-right">
                                            {isEditable ? (
                                                <Input
                                                    type="number"
                                                    min={0}
                                                    className="ml-auto w-32 text-right"
                                                    aria-label={`Harga satuan ${line.sku}`}
                                                    value={line.unit_cost ?? ''}
                                                    onChange={(event) =>
                                                        updateLine(index, {
                                                            unit_cost:
                                                                event.target
                                                                    .value ===
                                                                ''
                                                                    ? null
                                                                    : Number(
                                                                          event
                                                                              .target
                                                                              .value,
                                                                      ),
                                                        })
                                                    }
                                                />
                                            ) : (
                                                <span className="text-sm tabular-nums">
                                                    {line.unit_cost === null
                                                        ? '—'
                                                        : formatRupiah(
                                                              line.unit_cost,
                                                          )}
                                                </span>
                                            )}
                                        </TableCell>
                                    )}
                                    {isEditable && (
                                        <TableCell className="text-right">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="text-destructive hover:text-destructive"
                                                aria-label={`Hapus baris ${line.sku}`}
                                                onClick={() =>
                                                    removeLine(index)
                                                }
                                            >
                                                <Trash2 aria-hidden />
                                            </Button>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}

            <p className="text-sm text-muted-foreground">
                Total{' '}
                <strong className="text-foreground tabular-nums">
                    {formatNumber(
                        lines.reduce((total, line) => total + line.quantity, 0),
                    )}
                </strong>{' '}
                unit dalam {lines.length} baris.
            </p>
        </div>
    );
}
