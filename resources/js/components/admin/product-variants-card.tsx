import { Form, router } from '@inertiajs/react';
import { Boxes, Plus, Star } from 'lucide-react';
import { useState } from 'react';
import ProductVariantController from '@/actions/App/Http/Controllers/Admin/ProductVariantController';
import InputError from '@/components/input-error';
import { DataTable } from '@/components/panel/data-table';
import { StatusBadge } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatRupiah, formatWeight } from '@/lib/format';
import type {
    DataTableColumn,
    ProductVariantItem,
    ProductVariantSummary,
    VariantGeneratorAttribute,
} from '@/types';

type ProductVariantsCardProps = {
    product: ProductVariantSummary;
    variants: ProductVariantItem[];
    attributes: VariantGeneratorAttribute[];
    canUpdate: boolean;
    /** False for a `simple` product, whose single variant is the whole product. */
    canAdd: boolean;
};

/**
 * Every variant of one product, with its identity and its overrides
 * (P1-E3-T6).
 *
 * A price or weight cell shows the figure actually in force; when that figure
 * comes from the product rather than the variant it is marked as inherited, so
 * the difference between "250.000 because this variant says so" and "250.000
 * because the product says so" is visible without opening the row.
 */
export function ProductVariantsCard({
    product,
    variants,
    attributes,
    canUpdate,
    canAdd,
}: ProductVariantsCardProps) {
    const [isAdding, setIsAdding] = useState(false);
    const [editing, setEditing] = useState<ProductVariantItem | null>(null);
    const [pendingDelete, setPendingDelete] =
        useState<ProductVariantItem | null>(null);

    const addButton = canUpdate && canAdd && (
        <Button variant="secondary" onClick={() => setIsAdding(true)}>
            <Plus aria-hidden />
            Tambah Varian
        </Button>
    );

    const columns: DataTableColumn<ProductVariantItem>[] = [
        {
            key: 'name',
            header: 'Varian',
            cell: (variant) => (
                <div className="min-w-0">
                    <p className="flex flex-wrap items-center gap-2 font-medium">
                        {variant.display_name}
                        {variant.is_default && (
                            <span className="inline-flex items-center gap-1 text-xs font-normal text-muted-foreground">
                                <Star
                                    aria-hidden
                                    className="size-3.5 fill-amber-400 text-amber-500"
                                />
                                Varian utama
                            </span>
                        )}
                    </p>
                    {variant.options.length > 0 && (
                        <p className="mt-1 flex flex-wrap gap-1">
                            {variant.options.map((option) => (
                                <span
                                    key={option.value_id}
                                    className="inline-flex items-center gap-1 rounded-full bg-muted px-1.5 py-0.5 text-xs text-muted-foreground"
                                    title={option.attribute_name}
                                >
                                    {option.color_hex && (
                                        <span
                                            aria-hidden
                                            className="size-2.5 shrink-0 rounded-full border"
                                            style={{
                                                backgroundColor:
                                                    option.color_hex,
                                            }}
                                        />
                                    )}
                                    {option.label}
                                </span>
                            ))}
                        </p>
                    )}
                </div>
            ),
        },
        {
            key: 'sku',
            header: 'SKU / Barcode',
            cell: (variant) => (
                <div className="min-w-0">
                    <p className="truncate font-mono text-sm">{variant.sku}</p>
                    <p className="truncate font-mono text-xs text-muted-foreground">
                        {variant.barcode ?? '—'}
                    </p>
                </div>
            ),
        },
        {
            key: 'price',
            header: 'Harga',
            align: 'right',
            cell: (variant) => (
                <InheritedFigure
                    value={formatRupiah(variant.effective_price)}
                    isInherited={variant.price === null}
                />
            ),
        },
        {
            key: 'weight_gram',
            header: 'Berat',
            align: 'right',
            hideOnMobile: true,
            cell: (variant) => (
                <InheritedFigure
                    value={formatWeight(variant.effective_weight_gram)}
                    isInherited={variant.weight_gram === null}
                />
            ),
        },
        {
            key: 'is_active',
            header: 'Status',
            cell: (variant) => (
                <StatusBadge
                    label={variant.is_active ? 'Aktif' : 'Tidak Aktif'}
                    tone={variant.is_active ? 'success' : 'danger'}
                />
            ),
        },
        {
            key: 'actions',
            header: 'Aksi',
            srOnlyHeader: true,
            align: 'right',
            cell: (variant) => (
                <div className="flex flex-wrap items-center justify-end gap-1">
                    {variant.can.make_default && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                router.patch(
                                    ProductVariantController.setDefault([
                                        product.id,
                                        variant.id,
                                    ]).url,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Jadikan utama
                        </Button>
                    )}
                    {variant.can.update && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setEditing(variant)}
                        >
                            Ubah
                        </Button>
                    )}
                    {variant.can.delete && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-destructive hover:text-destructive"
                            onClick={() => setPendingDelete(variant)}
                        >
                            Hapus
                        </Button>
                    )}
                    {/* Disabled rather than hidden, so the reason the server
                        would refuse is visible up front. */}
                    {canUpdate &&
                        !variant.can.delete &&
                        variant.deletion_blocked_reason && (
                            <Button
                                variant="ghost"
                                size="sm"
                                disabled
                                title={variant.deletion_blocked_reason}
                            >
                                Hapus
                            </Button>
                        )}
                </div>
            ),
        },
    ];

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <CardTitle>Daftar varian</CardTitle>
                        <CardDescription>
                            Harga dan berat yang dibiarkan kosong mengikuti
                            produk, jadi angkanya tidak bisa melenceng tanpa
                            sengaja. Stok dan pesanan selalu menunjuk varian,
                            bukan produk.
                        </CardDescription>
                    </div>
                    {addButton}
                </div>
            </CardHeader>
            <CardContent>
                <DataTable
                    columns={columns}
                    rows={variants}
                    rowKey={(variant) => variant.id}
                    rowLabel="varian"
                    rowClassName={(variant) =>
                        variant.is_active ? undefined : 'opacity-60'
                    }
                    empty={{
                        icon: Boxes,
                        title: 'Belum ada varian',
                        description:
                            'Setiap produk seharusnya punya minimal satu varian. Buat varian dari atribut di atas.',
                    }}
                />
            </CardContent>

            <VariantDialog
                open={isAdding}
                onClose={() => setIsAdding(false)}
                product={product}
                attributes={attributes}
            />

            <VariantDialog
                // Remount for another variant, so the dialog's defaults are the
                // ones of the row that was clicked.
                key={editing?.id ?? 'edit'}
                open={editing !== null}
                onClose={() => setEditing(null)}
                product={product}
                attributes={attributes}
                variant={editing ?? undefined}
            />

            <DeleteVariantDialog
                productId={product.id}
                variant={pendingDelete}
                onClose={() => setPendingDelete(null)}
            />
        </Card>
    );
}

function InheritedFigure({
    value,
    isInherited,
}: {
    value: string;
    isInherited: boolean;
}) {
    return (
        <div className="text-sm">
            <p className="tabular-nums">{value}</p>
            {isInherited && (
                <p className="text-xs text-muted-foreground">ikut produk</p>
            )}
        </div>
    );
}

type VariantFormData = {
    /**
     * Submitted as repeated `attribute_value_ids[]` hidden inputs, and listed
     * here so the validation error keyed on it is typed.
     */
    attribute_value_ids: number[];
    sku: string;
    barcode: string;
    name: string;
    price: string;
    compare_at_price: string;
    cost_price: string;
    weight_gram: string;
    length_cm: string;
    width_cm: string;
    height_cm: string;
    is_active: string;
};

function VariantDialog({
    open,
    onClose,
    product,
    attributes,
    variant,
}: {
    open: boolean;
    onClose: () => void;
    product: ProductVariantSummary;
    attributes: VariantGeneratorAttribute[];
    variant?: ProductVariantItem;
}) {
    const isEditing = variant !== undefined;
    const [valueIds, setValueIds] = useState<number[]>(
        variant?.attribute_value_ids ?? [],
    );
    const [isActive, setIsActive] = useState(variant?.is_active ?? true);

    const action = isEditing
        ? ProductVariantController.update.form([product.id, variant.id])
        : ProductVariantController.store.form(product.id);

    const toggleValue = (
        attributeValueIds: number[],
        valueId: number,
    ): void => {
        setValueIds((current) => {
            if (current.includes(valueId)) {
                return current.filter((id) => id !== valueId);
            }

            // One value per attribute: picking a second replaces the first,
            // which is the same rule the form request enforces.
            return [
                ...current.filter((id) => !attributeValueIds.includes(id)),
                valueId,
            ];
        });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <Form<VariantFormData>
                    {...action}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    resetOnSuccess={!isEditing}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    {isEditing
                                        ? `Ubah varian ${variant.display_name}`
                                        : 'Tambah varian'}
                                </DialogTitle>
                                <DialogDescription>
                                    Biarkan harga, berat, dan dimensi kosong
                                    agar varian ini mengikuti produk.
                                </DialogDescription>
                            </DialogHeader>

                            {valueIds.map((valueId) => (
                                <input
                                    key={valueId}
                                    type="hidden"
                                    name="attribute_value_ids[]"
                                    value={valueId}
                                />
                            ))}
                            <input
                                type="hidden"
                                name="is_active"
                                value={isActive ? '1' : '0'}
                            />

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="variant_sku">SKU</Label>
                                    <Input
                                        id="variant_sku"
                                        name="sku"
                                        defaultValue={variant?.sku ?? ''}
                                        required
                                        maxLength={60}
                                        placeholder="KMJ-FLN-MERAH-XL"
                                        className="font-mono uppercase"
                                    />
                                    <InputError message={errors.sku} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="variant_barcode">
                                        Barcode{' '}
                                        <span className="text-muted-foreground">
                                            (opsional)
                                        </span>
                                    </Label>
                                    <Input
                                        id="variant_barcode"
                                        name="barcode"
                                        defaultValue={variant?.barcode ?? ''}
                                        maxLength={60}
                                        placeholder="8991102000123"
                                        className="font-mono"
                                    />
                                    <InputError message={errors.barcode} />
                                </div>

                                <div className="grid gap-2 sm:col-span-2">
                                    <Label htmlFor="variant_name">
                                        Nama varian{' '}
                                        <span className="text-muted-foreground">
                                            (opsional)
                                        </span>
                                    </Label>
                                    <Input
                                        id="variant_name"
                                        name="name"
                                        defaultValue={variant?.name ?? ''}
                                        maxLength={255}
                                        placeholder="Merah / XL"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Dibiarkan kosong, varian ini tampil
                                        dengan nama produk.
                                    </p>
                                    <InputError message={errors.name} />
                                </div>
                            </div>

                            {attributes.length > 0 && (
                                <div className="grid gap-3 rounded-lg border p-3">
                                    <div>
                                        <Label>Pilihan atribut</Label>
                                        <p className="text-xs text-muted-foreground">
                                            Maksimal satu nilai per atribut.
                                            Kombinasi harus berbeda dari varian
                                            lain di produk ini.
                                        </p>
                                    </div>
                                    {attributes.map((attribute) => {
                                        const ids = attribute.values.map(
                                            (value) => value.id,
                                        );

                                        return (
                                            <div
                                                key={attribute.id}
                                                className="grid gap-1.5"
                                            >
                                                <p className="text-xs font-medium text-muted-foreground">
                                                    {attribute.name}
                                                </p>
                                                <div className="flex flex-wrap gap-2">
                                                    {attribute.values.map(
                                                        (value) => {
                                                            const isPicked =
                                                                valueIds.includes(
                                                                    value.id,
                                                                );

                                                            return (
                                                                <button
                                                                    key={
                                                                        value.id
                                                                    }
                                                                    type="button"
                                                                    aria-pressed={
                                                                        isPicked
                                                                    }
                                                                    className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none ${
                                                                        isPicked
                                                                            ? 'border-primary bg-primary text-primary-foreground'
                                                                            : 'bg-background hover:bg-muted'
                                                                    }`}
                                                                    onClick={() =>
                                                                        toggleValue(
                                                                            ids,
                                                                            value.id,
                                                                        )
                                                                    }
                                                                >
                                                                    {value.color_hex && (
                                                                        <span
                                                                            aria-hidden
                                                                            className="size-3 shrink-0 rounded-full border"
                                                                            style={{
                                                                                backgroundColor:
                                                                                    value.color_hex,
                                                                            }}
                                                                        />
                                                                    )}
                                                                    {
                                                                        value.label
                                                                    }
                                                                </button>
                                                            );
                                                        },
                                                    )}
                                                </div>
                                            </div>
                                        );
                                    })}
                                    <InputError
                                        message={errors.attribute_value_ids}
                                    />
                                </div>
                            )}

                            <div className="grid gap-4 sm:grid-cols-3">
                                <OverrideField
                                    id="variant_price"
                                    name="price"
                                    label="Harga jual"
                                    defaultValue={variant?.price}
                                    inheritedFrom={product.base_price}
                                    error={errors.price}
                                />
                                <OverrideField
                                    id="variant_compare_at_price"
                                    name="compare_at_price"
                                    label="Harga sebelum diskon"
                                    defaultValue={variant?.compare_at_price}
                                    inheritedFrom={product.compare_at_price}
                                    error={errors.compare_at_price}
                                />
                                <OverrideField
                                    id="variant_cost_price"
                                    name="cost_price"
                                    label="Harga pokok"
                                    defaultValue={variant?.cost_price}
                                    inheritedFrom={product.cost_price}
                                    error={errors.cost_price}
                                />
                                <OverrideField
                                    id="variant_weight_gram"
                                    name="weight_gram"
                                    label="Berat (gram)"
                                    defaultValue={variant?.weight_gram}
                                    inheritedFrom={product.weight_gram}
                                    error={errors.weight_gram}
                                />
                                <OverrideField
                                    id="variant_length_cm"
                                    name="length_cm"
                                    label="Panjang (cm)"
                                    defaultValue={variant?.length_cm}
                                    inheritedFrom={product.length_cm}
                                    error={errors.length_cm}
                                />
                                <OverrideField
                                    id="variant_width_cm"
                                    name="width_cm"
                                    label="Lebar (cm)"
                                    defaultValue={variant?.width_cm}
                                    inheritedFrom={product.width_cm}
                                    error={errors.width_cm}
                                />
                                <OverrideField
                                    id="variant_height_cm"
                                    name="height_cm"
                                    label="Tinggi (cm)"
                                    defaultValue={variant?.height_cm}
                                    inheritedFrom={product.height_cm}
                                    error={errors.height_cm}
                                />
                            </div>

                            <div className="flex items-start gap-3 rounded-lg border p-3">
                                <Checkbox
                                    id="variant_is_active"
                                    checked={isActive}
                                    disabled={variant?.is_default ?? false}
                                    onCheckedChange={(checked) =>
                                        setIsActive(checked === true)
                                    }
                                />
                                <div className="grid gap-1">
                                    <Label htmlFor="variant_is_active">
                                        Varian aktif
                                    </Label>
                                    <p className="text-xs text-muted-foreground">
                                        {variant?.is_default
                                            ? 'Varian utama selalu aktif. Tunjuk varian lain sebagai varian utama untuk menonaktifkan yang ini.'
                                            : 'Varian nonaktif tidak bisa dibeli dan tidak muncul di storefront.'}
                                    </p>
                                    <InputError message={errors.is_active} />
                                </div>
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={onClose}
                                    disabled={processing}
                                >
                                    Batal
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {isEditing
                                        ? 'Simpan Perubahan'
                                        : 'Simpan Varian'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * A number input whose empty state means "follow the product", with the
 * product's own figure named as the placeholder so the fallback is visible
 * without leaving the dialog.
 */
function OverrideField({
    id,
    name,
    label,
    defaultValue,
    inheritedFrom,
    error,
}: {
    id: string;
    name: keyof Omit<VariantFormData, 'attribute_value_ids'>;
    label: string;
    defaultValue: number | null | undefined;
    inheritedFrom: number | null;
    error?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                name={name}
                type="number"
                min={0}
                defaultValue={defaultValue ?? ''}
                placeholder={
                    inheritedFrom === null
                        ? 'Kosong'
                        : `Ikut produk: ${inheritedFrom}`
                }
            />
            <InputError message={error} />
        </div>
    );
}

function DeleteVariantDialog({
    productId,
    variant,
    onClose,
}: {
    productId: number;
    variant: ProductVariantItem | null;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);

    const confirm = (): void => {
        if (!variant) {
            return;
        }

        router.delete(
            ProductVariantController.destroy([productId, variant.id]).url,
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    onClose();
                },
            },
        );
    };

    return (
        <Dialog
            open={variant !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Hapus varian?</DialogTitle>
                    <DialogDescription>
                        Varian <strong>{variant?.display_name}</strong> dihapus
                        secara lunak: riwayat pesanan yang menunjuk varian ini
                        tetap utuh, tetapi varian tidak lagi bisa dibeli.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={onClose}
                        disabled={processing}
                    >
                        Batal
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={confirm}
                        disabled={processing}
                    >
                        Hapus varian
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
