import { Head, Link, setLayoutProps } from '@inertiajs/react';
import CategoryController from '@/actions/App/Http/Controllers/Admin/CategoryController';
import { CategoryForm } from '@/components/admin/category-form';
import Heading from '@/components/heading';
import { StatusBadge } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatDateTime } from '@/lib/format';
import { edit, index as categoriesIndex } from '@/routes/admin/categories';
import type { CategoryDetail, CategoryParentOption } from '@/types';

type CategoriesEditProps = {
    category: CategoryDetail;
    parentOptions: CategoryParentOption[];
};

export default function CategoriesEdit({
    category,
    parentOptions,
}: CategoriesEditProps) {
    // The breadcrumb carries the category's name, so it comes from the page's
    // own props rather than a static `CategoriesEdit.layout` object.
    setLayoutProps({
        breadcrumbs: [
            { title: 'Kategori', href: categoriesIndex() },
            { title: category.name, href: edit(category.id) },
        ],
    });

    return (
        <>
            <Head title={`Kategori ${category.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={category.name}
                        description={`Level ${category.depth} · ${category.child_count} subkategori · ${category.product_count} produk · Dibuat ${formatDateTime(category.created_at)}`}
                    />
                    <StatusBadge
                        label={category.is_active ? 'Aktif' : 'Tidak Aktif'}
                        tone={category.is_active ? 'success' : 'danger'}
                    />
                </div>

                <Card className="max-w-4xl">
                    <CardHeader>
                        <CardTitle>Data kategori</CardTitle>
                        <CardDescription>
                            Memindahkan kategori ke induk lain ikut memindahkan
                            seluruh subkategorinya, selama hasilnya masih di
                            dalam batas 3 tingkat.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <CategoryForm
                            action={CategoryController.update.form(category.id)}
                            parentOptions={parentOptions}
                            category={category}
                            submitLabel="Simpan Perubahan"
                            secondaryAction={
                                <Button variant="ghost" asChild>
                                    <Link href={CategoryController.index()}>
                                        Kembali ke daftar
                                    </Link>
                                </Button>
                            }
                        />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
