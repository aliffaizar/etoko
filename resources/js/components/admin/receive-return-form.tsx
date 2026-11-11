import { useForm } from '@inertiajs/react';
import { PackageCheck } from 'lucide-react';
import { StatusBadge } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { formatRupiah } from '@/lib/format';
import returns from '@/routes/admin/returns';
import type { FilterOption } from '@/types';
import type { ReturnDetail, ReturnItemCondition } from '@/types/return';

type Line = {
    id: number;
    condition: ReturnItemCondition;
    restock: boolean;
    notes: string;
};

/**
 * The count taken when a returned parcel is opened (P2-E4-T3).
 *
 * The one screen in this epic that moves stock, so it is built around the
 * single question that decides the shelf: **what condition is each line
 * actually in?** The customer's claim is shown as the starting point and the
 * branch overwrites it, because a customer may say "rusak" and the branch may
 * find the goods fine.
 *
 * "Kembalikan ke stok" is disabled for two different reasons, and the form
 * says which: damaged goods are never restocked, and a line whose variant has
 * been removed from the catalogue has no shelf to go back to
 * (`is_restockable`). Both are enforced again in `ReceiveReturn`, so the tick
 * box is a convenience rather than the rule.
 */
export function ReceiveReturnForm({
    item,
    conditions,
}: {
    item: ReturnDetail;
    conditions: FilterOption[];
}) {
    const form = useForm<{ items: Line[]; notes: string }>({
        items: item.items.map((line) => ({
            id: line.id,
            condition: line.condition,
            restock: line.restock && line.is_restockable,
            notes: line.notes ?? '',
        })),
        notes: '',
    });

    const update = (index: number, patch: Partial<Line>): void => {
        form.setData(
            'items',
            form.data.items.map((line, position) =>
                position === index ? { ...line, ...patch } : line,
            ),
        );
    };

    const submit = (event: React.FormEvent): void => {
        event.preventDefault();
        form.post(returns.receive(item.id).url, { preserveScroll: true });
    };

    const restockedUnits = form.data.items.reduce((total, line, index) => {
        return line.restock ? total + item.items[index].quantity : total;
    }, 0);

    return (
        <form
            onSubmit={submit}
            className="flex flex-col gap-4 rounded-lg border p-4"
        >
            <div>
                <h2 className="flex items-center gap-2 text-sm font-semibold">
                    <PackageCheck aria-hidden className="size-4" />
                    Terima barang retur
                </h2>
                <p className="mt-1 text-xs text-muted-foreground">
                    Periksa setiap baris, lalu catat penerimaannya. Unit yang
                    layak jual akan dikembalikan ke stok cabang sebagai
                    &ldquo;Retur Penjualan&rdquo;.
                </p>
            </div>

            <ul className="flex flex-col gap-3">
                {item.items.map((line, index) => {
                    const state = form.data.items[index];
                    const damaged = state.condition === 'damaged';

                    return (
                        <li
                            key={line.id}
                            className="flex flex-col gap-3 rounded-md border p-3"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-medium">
                                        {line.display_name ??
                                            'Barang tidak dikenal'}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {line.sku} · {line.quantity} unit ·{' '}
                                        {formatRupiah(line.refundable_value)}
                                    </p>
                                </div>
                                {line.restocked_at && (
                                    <StatusBadge
                                        label="Sudah masuk stok"
                                        tone="success"
                                    />
                                )}
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <Label
                                        htmlFor={`condition-${line.id}`}
                                        className="text-xs"
                                    >
                                        Kondisi barang
                                    </Label>
                                    <Select
                                        value={state.condition}
                                        onValueChange={(value) =>
                                            update(index, {
                                                condition:
                                                    value as ReturnItemCondition,
                                                restock:
                                                    value === 'good' &&
                                                    line.is_restockable,
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            id={`condition-${line.id}`}
                                            className="mt-1"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {conditions.map((option) => (
                                                <SelectItem
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div>
                                    <Label
                                        htmlFor={`notes-${line.id}`}
                                        className="text-xs"
                                    >
                                        Catatan baris
                                    </Label>
                                    <Input
                                        id={`notes-${line.id}`}
                                        className="mt-1"
                                        maxLength={255}
                                        placeholder="Mis. segel terbuka, dus penyok."
                                        value={state.notes}
                                        onChange={(event) =>
                                            update(index, {
                                                notes: event.target.value,
                                            })
                                        }
                                    />
                                </div>
                            </div>

                            <div className="flex items-start gap-2">
                                <Checkbox
                                    id={`restock-${line.id}`}
                                    checked={state.restock}
                                    disabled={damaged || !line.is_restockable}
                                    onCheckedChange={(checked) =>
                                        update(index, {
                                            restock: checked === true,
                                        })
                                    }
                                />
                                <div className="grid gap-0.5">
                                    <Label
                                        htmlFor={`restock-${line.id}`}
                                        className="text-sm font-normal"
                                    >
                                        Kembalikan ke stok cabang
                                    </Label>
                                    {damaged ? (
                                        <span className="text-xs text-muted-foreground">
                                            Barang rusak tidak dikembalikan ke
                                            stok.
                                        </span>
                                    ) : (
                                        !line.is_restockable && (
                                            <span className="text-xs text-muted-foreground">
                                                Varian produk ini sudah tidak
                                                ada di katalog, jadi tidak ada
                                                stok yang bisa ditambah.
                                            </span>
                                        )
                                    )}
                                </div>
                            </div>
                        </li>
                    );
                })}
            </ul>

            <div>
                <Label htmlFor="receive-notes">Catatan penerimaan</Label>
                <Textarea
                    id="receive-notes"
                    className="mt-2"
                    rows={2}
                    maxLength={1000}
                    placeholder="Mis. diterima lewat JNE, resi JNE123456789."
                    value={form.data.notes}
                    onChange={(event) =>
                        form.setData('notes', event.target.value)
                    }
                />
                {form.errors.notes && (
                    <p className="mt-2 text-sm text-destructive">
                        {form.errors.notes}
                    </p>
                )}
            </div>

            {/* A refusal keyed on `status` — a parcel somebody else booked in
                first — lands in the page's own errors and is shown as the
                alert at the top of the screen, which is where a whole-document
                failure belongs. */}
            {form.errors.items && (
                <p className="text-sm text-destructive">{form.errors.items}</p>
            )}

            <div className="flex flex-wrap items-center gap-3 border-t pt-3">
                <Button type="submit" disabled={form.processing}>
                    <PackageCheck aria-hidden />
                    {form.processing ? 'Menyimpan…' : 'Catat penerimaan'}
                </Button>
                <span className="text-xs text-muted-foreground">
                    {restockedUnits > 0
                        ? `${restockedUnits} unit akan masuk kembali ke stok.`
                        : 'Tidak ada unit yang akan masuk kembali ke stok.'}
                </span>
            </div>
        </form>
    );
}
