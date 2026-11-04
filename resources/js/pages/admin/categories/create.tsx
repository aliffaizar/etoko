import { Head, Link } from '@inertiajs/react';
import CategoryController from '@/actions/App/Http/Controllers/Admin/CategoryController';
import { CategoryForm } from '@/components/admin/category-form';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { create, index as categoriesIndex } from '@/routes/admin/categories';
import type { CategoryParentOption } from '@/types';

type CategoriesCreateProps = {
    parentOptions: CategoryParentOption[];
};

export default function CategoriesCreate({
    parentOptions,
}: CategoriesCreateProps) {
    return (
        <>
            <Head title="Tambah Kategori" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Tambah Kategori"
                    description="Slug dibuat otomatis dari nama kategori dan bisa diubah setelah tersimpan."
                />

                <Card className="max-w-4xl">
                    <CardContent>
                        <CategoryForm
                            action={CategoryController.store.form()}
                            parentOptions={parentOptions}
                            submitLabel="Simpan Kategori"
                            secondaryAction={
                                <Button variant="ghost" asChild>
                                    <Link href={CategoryController.index()}>
                                        Batal
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

CategoriesCreate.layout = {
    breadcrumbs: [
        { title: 'Kategori', href: categoriesIndex() },
        { title: 'Tambah', href: create() },
    ],
};
