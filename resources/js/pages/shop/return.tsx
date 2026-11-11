import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Images, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import { formatRupiah } from '@/lib/format';
import account from '@/routes/shop/account';
import type { FilterOption } from '@/types';
import type { OrderRow } from '@/types/order';
import type { ReturnableLine, ReturnKind, ReturnReason } from '@/types/return';

type ReturnPageProps = {
    order: OrderRow;
    lines: ReturnableLine[];
    blocked_reason: string | null;
    options: { types: FilterOption[]; reasons: FilterOption[] };
    max_photos: number;
};

/**
 * Asking to send goods back (P2-E4-T2).
 *
 * A screen of its own rather than a panel on the order page, because it asks
 * for things the order page has nowhere to put: a quantity per line, a reason,
 * a paragraph, and photos. What stays on the order page is the *result*.
 *
 * Every line of the order is listed, each with a stepper capped at what may
 * still be sent back — a line already fully returned is shown with its reason
 * rather than hidden, so a customer is never left wondering where an item
 * went. The cap is the same number the server re-checks under the order's row
 * lock, so what the form offers is what will be accepted.
 *
 * "Alasan lain" makes the explanation required, which the form says before the
 * server does.
 */
export default function ReturnPage({
    order,
    lines,
    blocked_reason: blockedReason,
    options,
    max_photos: maxPhotos,
}: ReturnPageProps) {
    const [photos, setPhotos] = useState<File[]>([]);

    const form = useForm<{
        type: ReturnKind;
        reason: ReturnReason | '';
        description: string;
        items: { order_item_id: number; quantity: number }[];
        photos: File[];
    }>({
        type: 'refund',
        reason: '',
        description: '',
        items: lines.map((line) => ({
            order_item_id: line.id,
            quantity: 0,
        })),
        photos: [],
    });

    const selectedUnits = form.data.items.reduce(
        (total, line) => total + line.quantity,
        0,
    );

    const selectedValue = form.data.items.reduce((total, line, index) => {
        const source = lines[index];

        return (
            total +
            (source.quantity > 0
                ? Math.floor(source.subtotal / source.quantity) * line.quantity
                : 0)
        );
    }, 0);

    const setQuantity = (index: number, quantity: number): void => {
        form.setData(
            'items',
            form.data.items.map((line, position) =>
                position === index ? { ...line, quantity } : line,
            ),
        );
    };

    const pickPhotos = (files: FileList | null): void => {
        const chosen = Array.from(files ?? []).slice(0, maxPhotos);
        setPhotos(chosen);
        form.setData('photos', chosen);
    };

    const submit = (event: React.FormEvent): void => {
        event.preventDefault();
        form.post(account.orders.returns.store(order.id).url, {
            forceFormData: true,
        });
    };

    return (
        <>
            <Head title={`Ajukan retur ${order.order_number}`} />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-8">
                <div className="flex items-start gap-3">
                    <Button variant="ghost" size="icon" asChild>
                        <Link
                            href={account.orders.show(order.id).url}
                            aria-label="Kembali ke pesanan"
                        >
                            <ArrowLeft aria-hidden />
                        </Link>
                    </Button>
                    <div className="min-w-0">
                        <h1 className="flex items-center gap-2 text-xl font-semibold">
                            <Undo2 aria-hidden className="size-5" />
                            Ajukan retur
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Pesanan{' '}
                            <span className="tabular-nums">
                                {order.order_number}
                            </span>
                        </p>
                    </div>
                </div>

                {blockedReason ? (
                    <Alert>
                        <AlertTitle>Retur belum dapat diajukan</AlertTitle>
                        <AlertDescription>
                            <p>{blockedReason}</p>
                        </AlertDescription>
                    </Alert>
                ) : (
                    <form onSubmit={submit} className="flex flex-col gap-6">
                        <section className="flex flex-col gap-4 rounded-lg border p-4">
                            <div>
                                <h2 className="text-sm font-semibold">
                                    Pilih barang yang ingin diretur
                                </h2>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Atur jumlah untuk setiap barang. Barang yang
                                    tidak ingin diretur dibiarkan 0.
                                </p>
                            </div>

                            <ul className="flex flex-col gap-3">
                                {lines.map((line, index) => (
                                    <li
                                        key={line.id}
                                        className="flex flex-wrap items-center gap-3 rounded-md border p-3"
                                    >
                                        {line.image_url && (
                                            <img
                                                src={line.image_url}
                                                alt=""
                                                className="size-14 shrink-0 rounded-md border object-cover"
                                            />
                                        )}
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">
                                                {line.display_name}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {line.quantity} unit dibeli ·{' '}
                                                {formatRupiah(line.subtotal)}
                                            </p>
                                            {line.returnable_quantity === 0 && (
                                                <p className="text-xs text-muted-foreground">
                                                    Seluruh unit barang ini
                                                    sudah diajukan retur.
                                                </p>
                                            )}
                                        </div>

                                        <div className="w-24">
                                            <Label
                                                htmlFor={`quantity-${line.id}`}
                                                className="text-xs"
                                            >
                                                Jumlah
                                            </Label>
                                            <Input
                                                id={`quantity-${line.id}`}
                                                type="number"
                                                inputMode="numeric"
                                                min={0}
                                                max={line.returnable_quantity}
                                                disabled={
                                                    line.returnable_quantity ===
                                                    0
                                                }
                                                className="mt-1 tabular-nums"
                                                value={
                                                    form.data.items[index]
                                                        .quantity
                                                }
                                                onChange={(event) =>
                                                    setQuantity(
                                                        index,
                                                        Math.max(
                                                            0,
                                                            Math.min(
                                                                line.returnable_quantity,
                                                                Number(
                                                                    event.target
                                                                        .value,
                                                                ) || 0,
                                                            ),
                                                        ),
                                                    )
                                                }
                                            />
                                        </div>
                                    </li>
                                ))}
                            </ul>

                            {form.errors.items && (
                                <p className="text-sm text-destructive">
                                    {form.errors.items}
                                </p>
                            )}

                            <Separator />

                            <div className="flex justify-between gap-3 text-sm">
                                <span className="text-muted-foreground">
                                    {selectedUnits} unit dipilih
                                </span>
                                <span className="font-medium tabular-nums">
                                    {formatRupiah(selectedValue)}
                                </span>
                            </div>
                        </section>

                        <section className="flex flex-col gap-4 rounded-lg border p-4">
                            <h2 className="text-sm font-semibold">
                                Alasan retur
                            </h2>

                            <div className="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="return-reason">
                                        Alasan
                                    </Label>
                                    <Select
                                        value={form.data.reason || undefined}
                                        onValueChange={(value) =>
                                            form.setData(
                                                'reason',
                                                value as ReturnReason,
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            id="return-reason"
                                            className="mt-1"
                                        >
                                            <SelectValue placeholder="Pilih alasan" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {options.reasons.map((option) => (
                                                <SelectItem
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.reason && (
                                        <p className="mt-1 text-sm text-destructive">
                                            {form.errors.reason}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <Label htmlFor="return-type">
                                        Yang Anda inginkan
                                    </Label>
                                    <Select
                                        value={form.data.type}
                                        onValueChange={(value) =>
                                            form.setData(
                                                'type',
                                                value as ReturnKind,
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            id="return-type"
                                            className="mt-1"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {options.types.map((option) => (
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
                            </div>

                            <div>
                                <Label htmlFor="return-description">
                                    Penjelasan
                                    {form.data.reason !== 'other' && (
                                        <span className="font-normal text-muted-foreground">
                                            {' '}
                                            (opsional)
                                        </span>
                                    )}
                                </Label>
                                <Textarea
                                    id="return-description"
                                    className="mt-1"
                                    rows={4}
                                    maxLength={2000}
                                    placeholder="Ceritakan kondisi barang yang Anda terima."
                                    value={form.data.description}
                                    onChange={(event) =>
                                        form.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                />
                                {form.errors.description && (
                                    <p className="mt-1 text-sm text-destructive">
                                        {form.errors.description}
                                    </p>
                                )}
                            </div>

                            <div>
                                <Label
                                    htmlFor="return-photos"
                                    className="flex items-center gap-2"
                                >
                                    <Images aria-hidden className="size-4" />
                                    Foto barang
                                    <span className="font-normal text-muted-foreground">
                                        (maksimal {maxPhotos} foto)
                                    </span>
                                </Label>
                                <Input
                                    id="return-photos"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    multiple
                                    className="mt-1"
                                    onChange={(event) =>
                                        pickPhotos(event.target.files)
                                    }
                                />
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Foto sangat membantu cabang memutuskan lebih
                                    cepat, terutama untuk barang rusak atau
                                    salah kirim.
                                </p>
                                {photos.length > 0 && (
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {photos.length} foto dipilih.
                                    </p>
                                )}
                                {form.errors.photos && (
                                    <p className="mt-1 text-sm text-destructive">
                                        {form.errors.photos}
                                    </p>
                                )}
                            </div>
                        </section>

                        {form.errors.reason && (
                            <Alert variant="destructive">
                                <AlertTitle>
                                    Pengajuan tidak dapat dikirim
                                </AlertTitle>
                                <AlertDescription>
                                    <p>{form.errors.reason}</p>
                                </AlertDescription>
                            </Alert>
                        )}

                        <div className="flex flex-wrap gap-3">
                            <Button
                                type="submit"
                                disabled={
                                    form.processing || selectedUnits === 0
                                }
                            >
                                <Undo2 aria-hidden />
                                {form.processing
                                    ? 'Mengirim…'
                                    : 'Kirim pengajuan retur'}
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href={account.orders.show(order.id).url}>
                                    Batal
                                </Link>
                            </Button>
                        </div>
                    </form>
                )}
            </div>
        </>
    );
}
