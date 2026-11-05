import { Form, Head, router } from '@inertiajs/react';
import { Palette, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import ProductAttributeController from '@/actions/App/Http/Controllers/Admin/ProductAttributeController';
import Heading from '@/components/heading';
import { AttributeValuesEditor } from '@/components/admin/attribute-values-editor';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index as attributesIndex } from '@/routes/admin/product-attributes';
import type {
    ProductAttributeItem,
    ProductAttributeScreenOptions,
} from '@/types';

type ProductAttributesIndexProps = {
    attributes: ProductAttributeItem[];
    options: ProductAttributeScreenOptions;
    can: { create: boolean };
};

/**
 * The attributes variants are built from (P1-E3-T6, ADR-021).
 *
 * One card per attribute rather than a table: an attribute only reads clearly
 * next to the options it offers, and a catalogue has a handful of axes, not
 * hundreds — so there is no paginator and no separate create page either.
 *
 * Renaming an attribute renames it in every product that uses it, which is
 * the point of sharing them; the usage count on each card is what makes that
 * visible before someone edits.
 */
export default function ProductAttributesIndex({
    attributes,
    options,
    can,
}: ProductAttributesIndexProps) {
    const [isAdding, setIsAdding] = useState(false);
    const [editing, setEditing] = useState<ProductAttributeItem | null>(null);
    const [pendingDelete, setPendingDelete] =
        useState<ProductAttributeItem | null>(null);

    const addButton = can.create && (
        <Button onClick={() => setIsAdding(true)}>
            <Plus aria-hidden />
            Tambah Atribut
        </Button>
    );

    return (
        <>
            <Head title="Atribut Produk" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Atribut Produk"
                        description="Atribut dipakai bersama seluruh katalog: “Merah” berarti sama di semua produk. Dari atribut inilah varian produk dibentuk."
                    />
                    {addButton}
                </div>

                {attributes.length === 0 ? (
                    <Empty className="border border-dashed">
                        <EmptyHeader>
                            <EmptyMedia variant="icon">
                                <Palette aria-hidden />
                            </EmptyMedia>
                            <EmptyTitle>Belum ada atribut</EmptyTitle>
                            <EmptyDescription>
                                Tambahkan atribut seperti Warna atau Ukuran
                                beserta nilainya, lalu pakai di editor varian
                                produk.
                            </EmptyDescription>
                        </EmptyHeader>
                        {addButton}
                    </Empty>
                ) : (
                    <div className="grid gap-4 xl:grid-cols-2">
                        {attributes.map((attribute) => (
                            <AttributeCard
                                key={attribute.id}
                                attribute={attribute}
                                onRequestEdit={() => setEditing(attribute)}
                                onRequestDelete={() =>
                                    setPendingDelete(attribute)
                                }
                            />
                        ))}
                    </div>
                )}
            </div>

            <AttributeDialog
                open={isAdding}
                onClose={() => setIsAdding(false)}
                types={options.types}
            />

            <AttributeDialog
                // Remount for another attribute, so the dialog's defaults are
                // the ones of the card that was clicked.
                key={editing?.id ?? 'edit'}
                open={editing !== null}
                onClose={() => setEditing(null)}
                types={options.types}
                attribute={editing ?? undefined}
            />

            <DeleteAttributeDialog
                attribute={pendingDelete}
                onClose={() => setPendingDelete(null)}
            />
        </>
    );
}

function AttributeCard({
    attribute,
    onRequestEdit,
    onRequestDelete,
}: {
    attribute: ProductAttributeItem;
    onRequestEdit: () => void;
    onRequestDelete: () => void;
}) {
    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <CardTitle className="flex flex-wrap items-center gap-2">
                            {attribute.name}
                            {/* An attribute named after its own type — "Warna"
                                of type "Warna" — would read as a stutter, so
                                the badge is dropped when it adds nothing. */}
                            {attribute.type_label !== attribute.name && (
                                <span className="rounded-md bg-muted px-1.5 py-0.5 text-xs font-normal text-muted-foreground">
                                    {attribute.type_label}
                                </span>
                            )}
                        </CardTitle>
                        <CardDescription>
                            <span className="font-mono">{attribute.slug}</span>
                            {' · '}
                            {attribute.values.length} nilai
                            {' · '}
                            {attribute.variant_usage_count === 0
                                ? 'belum dipakai varian'
                                : `dipakai ${attribute.variant_usage_count} varian`}
                        </CardDescription>
                    </div>

                    <div className="flex shrink-0 items-center gap-1">
                        {attribute.can.update && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={onRequestEdit}
                            >
                                <Pencil aria-hidden />
                                Ubah
                            </Button>
                        )}
                        {attribute.can.delete && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="text-destructive hover:text-destructive"
                                onClick={onRequestDelete}
                            >
                                <Trash2 aria-hidden />
                                Hapus
                            </Button>
                        )}
                        {/* Shown disabled rather than hidden, so the reason the
                            server would refuse is visible up front. */}
                        {!attribute.can.delete &&
                            attribute.deletion_blocked_reason && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    disabled
                                    title={attribute.deletion_blocked_reason}
                                >
                                    <Trash2 aria-hidden />
                                    Hapus
                                </Button>
                            )}
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <AttributeValuesEditor attribute={attribute} />
            </CardContent>
        </Card>
    );
}

type AttributeFormData = {
    name: string;
    type: string;
    sort_order: string;
};

function AttributeDialog({
    open,
    onClose,
    types,
    attribute,
}: {
    open: boolean;
    onClose: () => void;
    types: { value: string; label: string }[];
    attribute?: ProductAttributeItem;
}) {
    const isEditing = attribute !== undefined;
    const action = isEditing
        ? ProductAttributeController.update.form(attribute.id)
        : ProductAttributeController.store.form();

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent>
                <Form<AttributeFormData>
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
                                        ? 'Ubah atribut'
                                        : 'Tambah atribut'}
                                </DialogTitle>
                                <DialogDescription>
                                    {isEditing
                                        ? 'Nama atribut berubah di seluruh produk yang memakainya. Tipe atribut tidak dapat diubah — buat atribut baru bila perlu tipe lain.'
                                        : 'Atribut berlaku untuk seluruh katalog. Nilainya ditambahkan setelah atribut tersimpan.'}
                                </DialogDescription>
                            </DialogHeader>

                            <div className="grid gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="attribute_name">
                                        Nama atribut
                                    </Label>
                                    <Input
                                        id="attribute_name"
                                        name="name"
                                        defaultValue={attribute?.name ?? ''}
                                        required
                                        maxLength={255}
                                        placeholder="Warna"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                {isEditing ? (
                                    <div className="grid gap-2">
                                        <Label>Tipe atribut</Label>
                                        <p className="text-sm text-muted-foreground">
                                            {attribute.type_label} — tidak dapat
                                            diubah.
                                        </p>
                                    </div>
                                ) : (
                                    <div className="grid gap-2">
                                        <Label htmlFor="attribute_type">
                                            Tipe atribut
                                        </Label>
                                        <Select
                                            name="type"
                                            defaultValue={types[0]?.value}
                                        >
                                            <SelectTrigger
                                                id="attribute_type"
                                                className="w-full"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {types.map((type) => (
                                                    <SelectItem
                                                        key={type.value}
                                                        value={type.value}
                                                    >
                                                        {type.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <p className="text-xs text-muted-foreground">
                                            Tipe “Warna” membuat setiap nilainya
                                            wajib punya kode warna, yang dipakai
                                            sebagai contoh warna di storefront.
                                        </p>
                                        <InputError message={errors.type} />
                                    </div>
                                )}

                                <div className="grid gap-2">
                                    <Label htmlFor="attribute_sort_order">
                                        Urutan
                                    </Label>
                                    <Input
                                        id="attribute_sort_order"
                                        name="sort_order"
                                        type="number"
                                        min={0}
                                        defaultValue={
                                            attribute?.sort_order ?? 0
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Menentukan urutan atribut di editor
                                        varian dan di nama varian (mis. “Merah /
                                        XL”).
                                    </p>
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
                                        : 'Simpan Atribut'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function DeleteAttributeDialog({
    attribute,
    onClose,
}: {
    attribute: ProductAttributeItem | null;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);

    const confirm = (): void => {
        if (!attribute) {
            return;
        }

        router.delete(ProductAttributeController.destroy(attribute.id).url, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                onClose();
            },
        });
    };

    return (
        <Dialog
            open={attribute !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Hapus atribut?</DialogTitle>
                    <DialogDescription>
                        Atribut <strong>{attribute?.name}</strong> dihapus
                        bersama seluruh nilainya. Atribut yang masih membentuk
                        varian produk tidak dapat dihapus.
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
                        Hapus atribut
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

ProductAttributesIndex.layout = {
    breadcrumbs: [{ title: 'Atribut Produk', href: attributesIndex() }],
};
