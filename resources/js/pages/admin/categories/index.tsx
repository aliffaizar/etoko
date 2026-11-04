import { Head, Link, router } from '@inertiajs/react';
import {
    FolderTree,
    MoreHorizontal,
    Pencil,
    Plus,
    ToggleLeft,
    ToggleRight,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import CategoryController from '@/actions/App/Http/Controllers/Admin/CategoryController';
import Heading from '@/components/heading';
import { DataTable } from '@/components/panel/data-table';
import { FilterBar } from '@/components/panel/filter-bar';
import { StatusBadge } from '@/components/panel/status-badge';
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
import { index as categoriesIndex } from '@/routes/admin/categories';
import type {
    CategoryListItem,
    DataTableColumn,
    FilterField,
    FilterOption,
} from '@/types';

type CategoriesIndexProps = {
    categories: CategoryListItem[];
    options: { statuses: FilterOption[] };
    can: { create: boolean };
};

/** Indentation per tree level, applied to the name cell. */
const INDENT_REM = 1.25;

/**
 * The category tree (P1-E3-T2).
 *
 * The listing is not paginated: a taxonomy of at most three levels is small
 * and only makes sense read whole, so `DataTable` is used without a paginator
 * the same way the role listing uses it.
 *
 * Filtering collapses the tree into a flat list of matches — a match two
 * levels down would otherwise be invisible unless its ancestors matched too —
 * and each row then shows its ancestor path instead of an indent.
 */
export default function CategoriesIndex({
    categories,
    options,
    can,
}: CategoriesIndexProps) {
    const [pendingDelete, setPendingDelete] = useState<CategoryListItem | null>(
        null,
    );

    const table = useTableQuery({
        only: ['categories', 'filters'],
        defaultSort: 'sort_order',
    });

    const filterFields: FilterField[] = [
        {
            type: 'search',
            key: 'search',
            label: 'Cari kategori',
            placeholder: 'Cari nama atau slug…',
        },
        {
            type: 'select',
            key: 'status',
            label: 'Status',
            options: options.statuses,
            allLabel: 'Semua status',
            className: 'sm:w-44',
        },
    ];

    const addButton = can.create && (
        <Button asChild>
            <Link href={CategoryController.create()}>
                <Plus aria-hidden />
                Tambah Kategori
            </Link>
        </Button>
    );

    const columns: DataTableColumn<CategoryListItem>[] = [
        {
            key: 'name',
            header: 'Kategori',
            sortKey: table.isFiltered ? 'name' : undefined,
            cell: (category) => (
                <div
                    className="min-w-0"
                    style={{
                        paddingInlineStart: `${(category.depth - 1) * INDENT_REM}rem`,
                    }}
                >
                    <p className="truncate font-medium">{category.name}</p>
                    <p className="truncate text-xs text-muted-foreground">
                        {category.path.length > 0 && (
                            <span>{category.path.join(' › ')} · </span>
                        )}
                        <span className="font-mono">{category.slug}</span>
                    </p>
                </div>
            ),
        },
        {
            key: 'level',
            header: 'Tingkat',
            hideOnMobile: true,
            cell: (category) => (
                <span className="text-sm text-muted-foreground">
                    {table.isFiltered ? '-' : `Level ${category.depth}`}
                </span>
            ),
        },
        {
            key: 'child_count',
            header: 'Subkategori',
            align: 'right',
            hideOnMobile: true,
            cell: (category) => (
                <span className="text-sm tabular-nums">
                    {category.child_count}
                </span>
            ),
        },
        {
            key: 'product_count',
            header: 'Produk',
            align: 'right',
            hideOnMobile: true,
            cell: (category) => (
                <span className="text-sm tabular-nums">
                    {category.product_count}
                </span>
            ),
        },
        {
            key: 'sort_order',
            header: 'Urutan',
            align: 'right',
            sortKey: table.isFiltered ? 'sort_order' : undefined,
            hideOnMobile: true,
            cell: (category) => (
                <span className="text-sm tabular-nums">
                    {category.sort_order}
                </span>
            ),
        },
        {
            key: 'is_active',
            header: 'Status',
            cell: (category) => (
                <StatusBadge
                    label={category.is_active ? 'Aktif' : 'Tidak Aktif'}
                    tone={category.is_active ? 'success' : 'danger'}
                />
            ),
        },
        {
            key: 'actions',
            header: 'Aksi',
            srOnlyHeader: true,
            align: 'right',
            cell: (category) => (
                <RowActions
                    category={category}
                    onRequestDelete={() => setPendingDelete(category)}
                />
            ),
        },
    ];

    return (
        <>
            <Head title="Kategori" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Kategori"
                    description="Susun kategori produk hingga 3 tingkat. Kategori berlaku untuk semua cabang."
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
                    rows={categories}
                    rowKey={(category) => category.id}
                    rowLabel="kategori"
                    sort={table.sort}
                    onSortChange={table.toggleSort}
                    isLoading={table.isLoading}
                    empty={{
                        icon: FolderTree,
                        title: table.isFiltered
                            ? 'Tidak ada kategori yang cocok'
                            : 'Belum ada kategori',
                        description: table.isFiltered
                            ? 'Ubah atau hapus filter untuk melihat kategori lain.'
                            : 'Tambahkan kategori pertama sebelum mulai mendata produk.',
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

            <DeleteCategoryDialog
                category={pendingDelete}
                onClose={() => setPendingDelete(null)}
            />
        </>
    );
}

function RowActions({
    category,
    onRequestDelete,
}: {
    category: CategoryListItem;
    onRequestDelete: () => void;
}) {
    if (
        !category.can.update &&
        !category.can.delete &&
        !category.delete_blocked_reason
    ) {
        return null;
    }

    const toggleStatus = (): void => {
        router.patch(
            CategoryController.updateStatus(category.id).url,
            { is_active: !category.is_active },
            { preserveScroll: true },
        );
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label={`Aksi untuk ${category.name}`}
                >
                    <MoreHorizontal aria-hidden />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {category.can.update && (
                    <>
                        <DropdownMenuItem asChild>
                            <Link href={CategoryController.edit(category.id)}>
                                <Pencil aria-hidden />
                                Ubah kategori
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={toggleStatus}>
                            {category.is_active ? (
                                <ToggleLeft aria-hidden />
                            ) : (
                                <ToggleRight aria-hidden />
                            )}
                            {category.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                        </DropdownMenuItem>
                    </>
                )}

                {category.can.delete && (
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

                {/* Shown disabled rather than hidden, so the reason the server
                    would refuse is visible instead of being discovered after
                    the confirmation dialog. */}
                {!category.can.delete && category.delete_blocked_reason && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            disabled
                            title={category.delete_blocked_reason}
                        >
                            <Trash2 aria-hidden />
                            Hapus
                        </DropdownMenuItem>
                        <p className="px-2 py-1 text-xs text-muted-foreground">
                            {category.delete_blocked_reason}
                        </p>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function DeleteCategoryDialog({
    category,
    onClose,
}: {
    category: CategoryListItem | null;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);

    const confirm = (): void => {
        if (!category) {
            return;
        }

        router.delete(CategoryController.destroy(category.id).url, {
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
            open={category !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Hapus kategori?</DialogTitle>
                    <DialogDescription>
                        Kategori <strong>{category?.name}</strong> dihapus
                        secara lunak: tautannya tetap terpakai bila nanti
                        dipulihkan, tetapi kategori tidak lagi muncul di
                        storefront maupun panel. Kategori yang masih memiliki
                        subkategori tidak dapat dihapus.
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
                        Hapus kategori
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

CategoriesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Kategori',
            href: categoriesIndex(),
        },
    ],
};
