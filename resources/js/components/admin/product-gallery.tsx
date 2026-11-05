import { Form, router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    GripVertical,
    Image as ImageIcon,
    Pencil,
    Star,
    Trash2,
    Upload,
} from 'lucide-react';
import { useEffect, useRef, useState, type DragEvent } from 'react';
import ProductImageController from '@/actions/App/Http/Controllers/Admin/ProductImageController';
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
import { cn } from '@/lib/utils';
import type {
    ProductImageItem,
    ProductImageLimits,
    ProductImageScreenOptions,
} from '@/types';

type ProductGalleryProps = {
    productId: number;
    images: ProductImageItem[];
    options: ProductImageScreenOptions;
    limits: ProductImageLimits;
    canUpdate: boolean;
};

/** Sentinel for "no variant", since Radix refuses an empty `SelectItem` value. */
const NO_VARIANT = '__none__';

/**
 * A product's photo gallery: upload, reorder, pick the primary, tie a photo to
 * a variant (P1-E3-T7).
 *
 * Reordering is a drag, and also two arrow buttons on every card. The buttons
 * are not a fallback nobody uses — a drag is unreachable by keyboard and
 * awkward on a touch screen, and the order of a product's photos is exactly
 * the kind of thing someone adjusts by one position.
 *
 * The new order is held locally while it is being arranged and sent once, so a
 * drag across five positions is one request rather than five.
 */
export function ProductGallery({
    productId,
    images,
    options,
    limits,
    canUpdate,
}: ProductGalleryProps) {
    const [order, setOrder] = useState<ProductImageItem[]>(images);
    const [draggingId, setDraggingId] = useState<number | null>(null);
    const [editing, setEditing] = useState<ProductImageItem | null>(null);
    const [pendingDelete, setPendingDelete] = useState<ProductImageItem | null>(
        null,
    );
    const [isSavingOrder, setIsSavingOrder] = useState(false);

    // The server is the source of truth: after an upload, a delete or a saved
    // reorder the props come back and replace whatever was being arranged.
    useEffect(() => setOrder(images), [images]);

    const hasPendingOrder =
        order.length === images.length &&
        order.some((image, index) => image.id !== images[index]?.id);

    const move = (from: number, to: number): void => {
        if (to < 0 || to >= order.length || from === to) {
            return;
        }

        setOrder((current) => {
            const next = [...current];
            const [moved] = next.splice(from, 1);
            next.splice(to, 0, moved);

            return next;
        });
    };

    const saveOrder = (): void => {
        router.put(
            ProductImageController.reorder(productId).url,
            { image_ids: order.map((image) => image.id) },
            {
                preserveScroll: true,
                onStart: () => setIsSavingOrder(true),
                onFinish: () => setIsSavingOrder(false),
            },
        );
    };

    return (
        <div className="flex flex-col gap-4">
            {canUpdate && (
                <UploadCard
                    productId={productId}
                    options={options}
                    limits={limits}
                />
            )}

            <Card>
                <CardHeader>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0">
                            <CardTitle>Galeri foto</CardTitle>
                            <CardDescription>
                                Foto pertama dalam urutan dipakai sebagai foto
                                utama: itulah gambar yang muncul di daftar
                                produk, keranjang, dan pesanan. Foto yang
                                ditandai varian akan tampil saat pembeli memilih
                                varian itu.
                            </CardDescription>
                        </div>
                        {hasPendingOrder && (
                            <div className="flex shrink-0 items-center gap-2">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setOrder(images)}
                                    disabled={isSavingOrder}
                                >
                                    Batalkan
                                </Button>
                                <Button
                                    size="sm"
                                    onClick={saveOrder}
                                    disabled={isSavingOrder}
                                >
                                    Simpan Urutan
                                </Button>
                            </div>
                        )}
                    </div>
                </CardHeader>
                <CardContent>
                    {order.length === 0 ? (
                        <Empty className="border border-dashed">
                            <EmptyHeader>
                                <EmptyMedia variant="icon">
                                    <ImageIcon aria-hidden />
                                </EmptyMedia>
                                <EmptyTitle>Belum ada foto</EmptyTitle>
                                <EmptyDescription>
                                    Produk tanpa foto tetap bisa dijual, tetapi
                                    tampil tanpa gambar di storefront dan daftar
                                    produk.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                            {order.map((image, index) => (
                                <GalleryCard
                                    key={image.id}
                                    image={image}
                                    index={index}
                                    total={order.length}
                                    productId={productId}
                                    canUpdate={canUpdate}
                                    isDragging={draggingId === image.id}
                                    onDragStart={() => setDraggingId(image.id)}
                                    onDragEnd={() => setDraggingId(null)}
                                    onDropOn={() => {
                                        if (draggingId === null) {
                                            return;
                                        }

                                        move(
                                            order.findIndex(
                                                (candidate) =>
                                                    candidate.id === draggingId,
                                            ),
                                            index,
                                        );
                                    }}
                                    onMove={(to) => move(index, to)}
                                    onRequestEdit={() => setEditing(image)}
                                    onRequestDelete={() =>
                                        setPendingDelete(image)
                                    }
                                />
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>

            <EditImageDialog
                key={editing?.id ?? 'edit'}
                productId={productId}
                image={editing}
                options={options}
                onClose={() => setEditing(null)}
            />

            <DeleteImageDialog
                productId={productId}
                image={pendingDelete}
                onClose={() => setPendingDelete(null)}
            />
        </div>
    );
}

function GalleryCard({
    image,
    index,
    total,
    productId,
    canUpdate,
    isDragging,
    onDragStart,
    onDragEnd,
    onDropOn,
    onMove,
    onRequestEdit,
    onRequestDelete,
}: {
    image: ProductImageItem;
    index: number;
    total: number;
    productId: number;
    canUpdate: boolean;
    isDragging: boolean;
    onDragStart: () => void;
    onDragEnd: () => void;
    onDropOn: () => void;
    onMove: (to: number) => void;
    onRequestEdit: () => void;
    onRequestDelete: () => void;
}) {
    const [isDropTarget, setIsDropTarget] = useState(false);

    const allowDrop = (event: DragEvent<HTMLLIElement>): void => {
        if (!canUpdate) {
            return;
        }

        event.preventDefault();
        setIsDropTarget(true);
    };

    return (
        <li
            draggable={canUpdate}
            onDragStart={onDragStart}
            onDragEnd={() => {
                setIsDropTarget(false);
                onDragEnd();
            }}
            onDragOver={allowDrop}
            onDragLeave={() => setIsDropTarget(false)}
            onDrop={(event) => {
                event.preventDefault();
                setIsDropTarget(false);
                onDropOn();
            }}
            className={cn(
                'group relative flex flex-col overflow-hidden rounded-lg border bg-card transition-opacity',
                canUpdate && 'cursor-grab active:cursor-grabbing',
                isDragging && 'opacity-40',
                isDropTarget && 'ring-2 ring-primary',
            )}
        >
            <div className="relative aspect-square bg-muted">
                <img
                    src={image.url}
                    alt={image.alt_text ?? ''}
                    loading="lazy"
                    className="size-full object-cover"
                />

                {image.is_primary && (
                    <span className="absolute start-1.5 top-1.5 inline-flex items-center gap-1 rounded-full bg-background/90 px-1.5 py-0.5 text-xs font-medium shadow-sm">
                        <Star
                            aria-hidden
                            className="size-3 fill-amber-400 text-amber-500"
                        />
                        Utama
                    </span>
                )}

                {canUpdate && (
                    <GripVertical
                        aria-hidden
                        className="absolute end-1.5 top-1.5 size-4 text-foreground/40 opacity-0 transition-opacity group-hover:opacity-100"
                    />
                )}
            </div>

            <div className="flex min-w-0 flex-col gap-2 p-2">
                <div className="min-w-0">
                    <p className="truncate text-xs text-muted-foreground">
                        {image.alt_text ?? 'Tanpa teks alternatif'}
                    </p>
                    {image.variant_label && (
                        <p className="mt-1">
                            <span className="inline-block max-w-full truncate rounded-full bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
                                {image.variant_label}
                            </span>
                        </p>
                    )}
                </div>

                {canUpdate && (
                    <div className="flex flex-wrap items-center gap-0.5">
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-7"
                            aria-label="Pindah ke kiri"
                            disabled={index === 0}
                            onClick={() => onMove(index - 1)}
                        >
                            <ArrowLeft aria-hidden />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-7"
                            aria-label="Pindah ke kanan"
                            disabled={index === total - 1}
                            onClick={() => onMove(index + 1)}
                        >
                            <ArrowRight aria-hidden />
                        </Button>
                        {image.can.make_primary && (
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-7"
                                aria-label="Jadikan foto utama"
                                title="Jadikan foto utama"
                                onClick={() =>
                                    router.patch(
                                        ProductImageController.setPrimary([
                                            productId,
                                            image.id,
                                        ]).url,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Star aria-hidden />
                            </Button>
                        )}
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-7"
                            aria-label="Ubah keterangan foto"
                            onClick={onRequestEdit}
                        >
                            <Pencil aria-hidden />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-7 text-destructive hover:text-destructive"
                            aria-label="Hapus foto"
                            onClick={onRequestDelete}
                        >
                            <Trash2 aria-hidden />
                        </Button>
                    </div>
                )}
            </div>
        </li>
    );
}

type UploadFormData = {
    images: File[];
    product_variant_id: string;
};

function UploadCard({
    productId,
    options,
    limits,
}: {
    productId: number;
    options: ProductImageScreenOptions;
    limits: ProductImageLimits;
}) {
    const fileInput = useRef<HTMLInputElement>(null);

    return (
        <Card>
            <CardHeader>
                <CardTitle>Unggah foto</CardTitle>
                <CardDescription>
                    JPG, PNG, atau WEBP · maksimal {limits.max_kilobytes / 1024}{' '}
                    MB dan {limits.max_files} foto per unggahan · minimal{' '}
                    {limits.min_dimension}×{limits.min_dimension} piksel.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form<UploadFormData>
                    {...ProductImageController.store.form(productId)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => {
                        if (fileInput.current) {
                            fileInput.current.value = '';
                        }
                    }}
                    className="flex flex-col gap-4 sm:flex-row sm:items-end"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid min-w-0 flex-1 gap-2">
                                <Label htmlFor="image_files">Pilih foto</Label>
                                <Input
                                    ref={fileInput}
                                    id="image_files"
                                    name="images[]"
                                    type="file"
                                    multiple
                                    required
                                    accept="image/jpeg,image/png,image/webp"
                                    className="file:me-3 file:text-foreground"
                                />
                                <InputError message={errors.images} />
                                {/* Per-file errors arrive keyed by index. */}
                                <InputError
                                    message={
                                        (
                                            errors as unknown as Record<
                                                string,
                                                string | undefined
                                            >
                                        )['images.0']
                                    }
                                />
                            </div>

                            {options.variants.length > 1 && (
                                <div className="grid gap-2 sm:w-64">
                                    <Label htmlFor="upload_variant">
                                        Khusus varian{' '}
                                        <span className="text-muted-foreground">
                                            (opsional)
                                        </span>
                                    </Label>
                                    <Select name="product_variant_id">
                                        <SelectTrigger
                                            id="upload_variant"
                                            className="w-full"
                                        >
                                            <SelectValue placeholder="Semua varian" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {options.variants.map((variant) => (
                                                <SelectItem
                                                    key={variant.value}
                                                    value={String(
                                                        variant.value,
                                                    )}
                                                >
                                                    {variant.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={errors.product_variant_id}
                                    />
                                </div>
                            )}

                            <Button type="submit" disabled={processing}>
                                <Upload aria-hidden />
                                Unggah
                            </Button>
                        </>
                    )}
                </Form>
            </CardContent>
        </Card>
    );
}

type EditImageFormData = {
    alt_text: string;
    product_variant_id: string;
};

function EditImageDialog({
    productId,
    image,
    options,
    onClose,
}: {
    productId: number;
    image: ProductImageItem | null;
    options: ProductImageScreenOptions;
    onClose: () => void;
}) {
    if (!image) {
        return null;
    }

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <Form<EditImageFormData>
                    {...ProductImageController.update.form([
                        productId,
                        image.id,
                    ])}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Keterangan foto</DialogTitle>
                                <DialogDescription>
                                    Teks alternatif dibaca pembaca layar dan
                                    tampil bila gambar gagal dimuat. Fotonya
                                    sendiri tidak bisa diganti — unggah foto
                                    baru lalu hapus yang lama.
                                </DialogDescription>
                            </DialogHeader>

                            <img
                                src={image.url}
                                alt={image.alt_text ?? ''}
                                className="mx-auto max-h-56 rounded-lg border object-contain"
                            />

                            <div className="grid gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="image_alt_text">
                                        Teks alternatif
                                    </Label>
                                    <Input
                                        id="image_alt_text"
                                        name="alt_text"
                                        defaultValue={image.alt_text ?? ''}
                                        maxLength={255}
                                        placeholder="Kaos hijau dilihat dari depan"
                                    />
                                    <InputError message={errors.alt_text} />
                                </div>

                                {options.variants.length > 1 && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="image_variant">
                                            Khusus varian
                                        </Label>
                                        <Select
                                            name="product_variant_id"
                                            defaultValue={
                                                image.product_variant_id ===
                                                null
                                                    ? NO_VARIANT
                                                    : String(
                                                          image.product_variant_id,
                                                      )
                                            }
                                        >
                                            <SelectTrigger
                                                id="image_variant"
                                                className="w-full"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value={NO_VARIANT}>
                                                    Semua varian
                                                </SelectItem>
                                                {options.variants.map(
                                                    (variant) => (
                                                        <SelectItem
                                                            key={variant.value}
                                                            value={String(
                                                                variant.value,
                                                            )}
                                                        >
                                                            {variant.label}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            message={errors.product_variant_id}
                                        />
                                    </div>
                                )}
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
                                    Simpan Perubahan
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function DeleteImageDialog({
    productId,
    image,
    onClose,
}: {
    productId: number;
    image: ProductImageItem | null;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);

    const confirm = (): void => {
        if (!image) {
            return;
        }

        router.delete(
            ProductImageController.destroy([productId, image.id]).url,
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
            open={image !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Hapus foto?</DialogTitle>
                    <DialogDescription>
                        Foto dan berkasnya dihapus permanen dan tidak bisa
                        dipulihkan.
                        {image?.is_primary &&
                            ' Karena ini foto utama, foto berikutnya dalam urutan otomatis menggantikannya.'}
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
                        Hapus foto
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
