import { Head, Link, router } from '@inertiajs/react';
import {
    Boxes,
    Images,
    MoreHorizontal,
    Package,
    Pencil,
    Plus,
    Star,
    StarOff,
    ToggleLeft,
    ToggleRight,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import ProductController from '@/actions/App/Http/Controllers/Admin/ProductController';
import Heading from '@/components/heading';
import { DataTable } from '@/components/panel/data-table';
import { FilterBar } from '@/components/panel/filter-bar';
import { StatusBadge, type StatusTone } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useTableQuery } from '@/hooks/use-table-query';
import { formatRupiah, formatWeight } from '@/lib/format';
import { index as productsIndex } from '@/routes/admin/products';
import images from '@/routes/admin/products/images';
import variants from '@/routes/admin/products/variants';
import type {
    DataTableColumn,
    FilterField,
    Paginated,
    ProductListItem,
    ProductPublicationState,
    ProductScreenOptions,
} from '@/types';

type ProductsIndexProps = {
    products: Paginated<ProductListItem>;
    options: ProductScreenOptions;
    can: { create: boolean };
};

const FEATURED_OPTIONS = [
    { value: 'yes', label: 'Hanya unggulan' },
    { value: 'no', label: 'Bukan unggulan' },
];

/** How each publication state reads at a glance. */
const STATE_TONE: Record<ProductPublicationState, StatusTone> = {
    published: 'success',
    scheduled: 'info',
    draft: 'neutral',
    inactive: 'danger',
};

export default function ProductsIndex({
    products,
    options,
    can,
}: ProductsIndexProps) {
    const [pendingDelete, setPendingDelete] = useState<ProductListItem | null>(
        null,
    );

    const table = useTableQuery({
        only: ['products', 'filters'],
        defaultSort: 'created_at',
        defaultDirection: 'desc',
    });

    const filterFields: FilterField[] = [
        {
            type: 'search',
            key: 'search',
            label: 'Cari produk',
            placeholder: 'Cari nama, slug, SKU, atau barcode…',
        },
        {
            type: 'select',
            key: 'category',
            label: 'Kategori',
            options: options.categories.map((category) => ({
                value: String(category.value),
                label: `${'— '.repeat(category.depth - 1)}${category.label}`,
            })),
            allLabel: 'Semua kategori',
            className: 'sm:w-56',
        },
        {
            type: 'select',
            key: 'state',
            label: 'Status',
            options: options.states,
            allLabel: 'Semua status',
            className: 'sm:w-44',
        },
        {
            type: 'select',
            key: 'type',
            label: 'Tipe',
            options: options.types,
            allLabel: 'Semua tipe',
            className: 'sm:w-48',
        },
        {
            type: 'select',
            key: 'featured',
            label: 'Unggulan',
            options: FEATURED_OPTIONS,
            allLabel: 'Semua produk',
            className: 'sm:w-44',
        },
    ];

    const addButton = can.create && (
        <Button asChild>
            <Link href={ProductController.create()}>
                <Plus aria-hidden />
                Tambah Produk
            </Link>
        </Button>
    );

    const columns: DataTableColumn<ProductListItem>[] = [
        {
            key: 'name',
            header: 'Produk',
            sortKey: 'name',
            cell: (product) => (
                <div className="min-w-0">
                    <p className="flex items-center gap-2 truncate font-medium">
                        {product.name}
                        {product.is_featured && (
                            <Star
                                aria-label="Produk unggulan"
                                className="size-3.5 shrink-0 fill-amber-400 text-amber-500"
                            />
                        )}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                        {product.sku ?? '—'} · {product.category_name}
                    </p>
                </div>
            ),
        },
        {
            key: 'base_price',
            header: 'Harga',
            align: 'right',
            sortKey: 'base_price',
            cell: (product) => (
                <div className="tabular-nums">
                    <p className="text-sm">
                        {formatRupiah(product.base_price)}
                    </p>
                    {product.compare_at_price !== null && (
                        <p className="text-xs text-muted-foreground line-through">
                            {formatRupiah(product.compare_at_price)}
                        </p>
                    )}
                </div>
            ),
        },
        {
            key: 'type',
            header: 'Tipe',
            hideOnMobile: true,
            cell: (product) => (
                <div>
                    <p className="text-sm">{product.type_label}</p>
                    <p className="text-xs text-muted-foreground">
                        {product.variant_count} varian
                    </p>
                </div>
            ),
        },
        {
            key: 'weight_gram',
            header: 'Berat',
            align: 'right',
            hideOnMobile: true,
            cell: (product) => (
                <span className="text-sm tabular-nums">
                    {formatWeight(product.weight_gram)}
                </span>
            ),
        },
        {
            key: 'sold_count',
            header: 'Terjual',
            align: 'right',
            sortKey: 'sold_count',
            hideOnMobile: true,
            cell: (product) => (
                <span className="text-sm tabular-nums">
                    {product.sold_count}
                </span>
            ),
        },
        {
            key: 'state',
            header: 'Status',
            cell: (product) => (
                <StatusBadge
                    label={product.state_label}
                    tone={STATE_TONE[product.state]}
                />
            ),
        },
        {
            key: 'actions',
            header: 'Aksi',
            srOnlyHeader: true,
            align: 'right',
            cell: (product) => (
                <RowActions
                    product={product}
                    onRequestDelete={() => setPendingDelete(product)}
                />
            ),
        },
    ];

    return (
        <>
            <Head title="Produk" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Produk"
                    description="Katalog produk berlaku untuk semua cabang. Ketersediaan dan harga khusus per cabang diatur terpisah."
                />

                <FilterBar
                    fields={filterFields}
                    values={table.values}
                    onChange={table.setFilters}
                    onReset={table.reset}
                    isFiltered={table.isFiltered}
                    actions={addButton}
                />

                <DataTable
                    columns={columns}
                    rows={products.data}
                    rowKey={(product) => product.id}
                    paginator={products}
                    rowLabel="produk"
                    sort={table.sort}
                    onSortChange={table.toggleSort}
                    goToPage={table.goToPage}
                    pageUrl={table.pageUrl}
                    isLoading={table.isLoading}
                    empty={{
                        icon: Package,
                        title: table.isFiltered
                            ? 'Tidak ada produk yang cocok'
                            : 'Belum ada produk',
                        description: table.isFiltered
                            ? 'Ubah atau hapus filter untuk melihat produk lain.'
                            : 'Tambahkan produk pertama ke katalog.',
                        action: table.isFiltered ? (
                            <Button variant="outline" onClick={table.reset}>
                                Reset filter
                            </Button>
                        ) : (
                            addButton
                        ),
                    }}
                />
            </div>

            <DeleteProductDialog
                product={pendingDelete}
                onClose={() => setPendingDelete(null)}
            />
        </>
    );
}

function RowActions({
    product,
    onRequestDelete,
}: {
    product: ProductListItem;
    onRequestDelete: () => void;
}) {
    // The variant list is readable with `products.view` alone, which warehouse
    // staff and cashiers hold — so the menu is not empty for them even though
    // every write is hidden.

    const patch = (payload: Record<string, boolean>): void => {
        router.patch(ProductController.updateStatus(product.id).url, payload, {
            preserveScroll: true,
        });
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label={`Aksi untuk ${product.name}`}
                >
                    <MoreHorizontal aria-hidden />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem asChild>
                    <Link href={variants.index(product.id)}>
                        <Boxes aria-hidden />
                        Varian ({product.variant_count})
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link href={images.index(product.id)}>
                        <Images aria-hidden />
                        Foto produk
                    </Link>
                </DropdownMenuItem>

                {product.can.update && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem asChild>
                            <Link href={ProductController.edit(product.id)}>
                                <Pencil aria-hidden />
                                Ubah produk
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            onClick={() =>
                                patch({ is_active: !product.is_active })
                            }
                        >
                            {product.is_active ? (
                                <ToggleLeft aria-hidden />
                            ) : (
                                <ToggleRight aria-hidden />
                            )}
                            {product.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            onClick={() =>
                                patch({ is_featured: !product.is_featured })
                            }
                        >
                            {product.is_featured ? (
                                <StarOff aria-hidden />
                            ) : (
                                <Star aria-hidden />
                            )}
                            {product.is_featured
                                ? 'Lepas dari unggulan'
                                : 'Jadikan unggulan'}
                        </DropdownMenuItem>
                    </>
                )}

                {product.can.delete && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            onClick={onRequestDelete}
                        >
                            <Trash2 aria-hidden />
                            Hapus
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function DeleteProductDialog({
    product,
    onClose,
}: {
    product: ProductListItem | null;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);

    const confirm = (): void => {
        if (!product) {
            return;
        }

        router.delete(ProductController.destroy(product.id).url, {
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
            open={product !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Hapus produk?</DialogTitle>
                    <DialogDescription>
                        Produk <strong>{product?.name}</strong> dihapus secara
                        lunak: varian, stok, dan riwayat pesanannya tetap
                        tersimpan, tetapi produk tidak lagi muncul di storefront
                        maupun katalog panel.
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
                        Hapus produk
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

ProductsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Produk',
            href: productsIndex(),
        },
    ],
};
