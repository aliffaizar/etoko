import { Form, router } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';
import ProductAttributeValueController from '@/actions/App/Http/Controllers/Admin/ProductAttributeValueController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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
import type { ProductAttributeItem, ProductAttributeValueItem } from '@/types';

type AttributeValuesEditorProps = {
    attribute: ProductAttributeItem;
};

/**
 * The values of one attribute, edited in place (P1-E3-T6).
 *
 * Values are chips rather than table rows: they are short, there are many, and
 * what matters at a glance is the set, not any one row's columns. A value in
 * use by a variant keeps its remove button hidden and says why — deleting it
 * would cascade through the pivot and leave that variant without the option
 * telling it apart from its siblings.
 */
export function AttributeValuesEditor({
    attribute,
}: AttributeValuesEditorProps) {
    const [isAdding, setIsAdding] = useState(false);
    const [editing, setEditing] = useState<ProductAttributeValueItem | null>(
        null,
    );

    const removeValue = (value: ProductAttributeValueItem): void => {
        router.delete(
            ProductAttributeValueController.destroy([attribute.id, value.id])
                .url,
            { preserveScroll: true },
        );
    };

    return (
        <div className="flex flex-col gap-3">
            {attribute.values.length === 0 ? (
                <p className="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">
                    Belum ada nilai. Atribut tanpa nilai belum bisa dipakai
                    membuat varian.
                </p>
            ) : (
                <ul className="flex flex-wrap gap-2">
                    {attribute.values.map((value) => (
                        <li key={value.id}>
                            <span className="inline-flex items-center gap-1.5 rounded-full border bg-background py-1 ps-2.5 pe-1.5 text-sm">
                                {value.color_hex && (
                                    <span
                                        aria-hidden
                                        className="size-3.5 shrink-0 rounded-full border"
                                        style={{
                                            backgroundColor: value.color_hex,
                                        }}
                                    />
                                )}
                                <button
                                    type="button"
                                    className="rounded-sm underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-default disabled:no-underline"
                                    disabled={!attribute.can.update}
                                    onClick={() => setEditing(value)}
                                >
                                    {value.display_label}
                                </button>
                                {value.variant_count > 0 ? (
                                    <span
                                        className="rounded-full bg-muted px-1.5 text-xs text-muted-foreground"
                                        title={`Dipakai ${value.variant_count} varian, jadi tidak dapat dihapus.`}
                                    >
                                        {value.variant_count}
                                    </span>
                                ) : (
                                    attribute.can.update && (
                                        <button
                                            type="button"
                                            aria-label={`Hapus nilai ${value.display_label}`}
                                            className="rounded-full p-0.5 text-muted-foreground hover:bg-destructive/10 hover:text-destructive focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                            onClick={() => removeValue(value)}
                                        >
                                            <X aria-hidden className="size-3" />
                                        </button>
                                    )
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            {attribute.can.update && (
                <div>
                    <Button
                        variant="secondary"
                        size="sm"
                        onClick={() => setIsAdding(true)}
                    >
                        <Plus aria-hidden />
                        Tambah Nilai
                    </Button>
                </div>
            )}

            <ValueDialog
                open={isAdding}
                onClose={() => setIsAdding(false)}
                attribute={attribute}
            />

            <ValueDialog
                // Remount for another value, so the dialog's defaults are the
                // ones of the chip that was clicked.
                key={editing?.id ?? 'edit'}
                open={editing !== null}
                onClose={() => setEditing(null)}
                attribute={attribute}
                value={editing ?? undefined}
            />
        </div>
    );
}

type ValueFormData = {
    value: string;
    label: string;
    color_hex: string;
    sort_order: string;
};

function ValueDialog({
    open,
    onClose,
    attribute,
    value,
}: {
    open: boolean;
    onClose: () => void;
    attribute: ProductAttributeItem;
    value?: ProductAttributeValueItem;
}) {
    const isEditing = value !== undefined;
    const action = isEditing
        ? ProductAttributeValueController.update.form([attribute.id, value.id])
        : ProductAttributeValueController.store.form(attribute.id);

    const defaultSortOrder = value?.sort_order ?? attribute.values.length;

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent>
                <Form<ValueFormData>
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
                                        ? `Ubah nilai ${attribute.name}`
                                        : `Tambah nilai ${attribute.name}`}
                                </DialogTitle>
                                <DialogDescription>
                                    Nilai ini tersedia untuk seluruh produk yang
                                    memakai atribut {attribute.name}.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="grid gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="attribute_value">
                                        Nilai
                                    </Label>
                                    <Input
                                        id="attribute_value"
                                        name="value"
                                        defaultValue={value?.value ?? ''}
                                        required
                                        maxLength={255}
                                        placeholder={
                                            attribute.uses_color_swatch
                                                ? 'Merah'
                                                : 'XL'
                                        }
                                    />
                                    <InputError message={errors.value} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="attribute_value_label">
                                        Label tampilan{' '}
                                        <span className="text-muted-foreground">
                                            (opsional)
                                        </span>
                                    </Label>
                                    <Input
                                        id="attribute_value_label"
                                        name="label"
                                        defaultValue={value?.label ?? ''}
                                        maxLength={255}
                                        placeholder="Extra Large"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Dipakai di storefront bila nilainya
                                        perlu ditulis lebih panjang.
                                    </p>
                                    <InputError message={errors.label} />
                                </div>

                                {attribute.uses_color_swatch && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="attribute_value_color">
                                            Kode warna
                                        </Label>
                                        <div className="flex items-center gap-2">
                                            <Input
                                                id="attribute_value_color"
                                                name="color_hex"
                                                type="color"
                                                defaultValue={
                                                    value?.color_hex ??
                                                    '#c62828'
                                                }
                                                className="h-9 w-16 p-1"
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                Warna ini yang ditampilkan
                                                sebagai contoh di storefront.
                                            </p>
                                        </div>
                                        <InputError
                                            message={errors.color_hex}
                                        />
                                    </div>
                                )}

                                <div className="grid gap-2">
                                    <Label htmlFor="attribute_value_sort">
                                        Urutan
                                    </Label>
                                    <Input
                                        id="attribute_value_sort"
                                        name="sort_order"
                                        type="number"
                                        min={0}
                                        defaultValue={defaultSortOrder}
                                    />
                                    <InputError message={errors.sort_order} />
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
                                        : 'Simpan Nilai'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
