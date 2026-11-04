import { Head, Link } from '@inertiajs/react';
import ProductController from '@/actions/App/Http/Controllers/Admin/ProductController';
import Heading from '@/components/heading';
import { ProductForm } from '@/components/admin/product-form';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { create, index as productsIndex } from '@/routes/admin/products';
import type { ProductFormOptions } from '@/types';

type ProductsCreateProps = {
    options: ProductFormOptions;
};

export default function ProductsCreate({ options }: ProductsCreateProps) {
    return (
        <>
            <Head title="Tambah Produk" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Tambah Produk"
                    description="Slug dibuat otomatis dari nama produk. Produk baru langsung mendapat satu varian utama dari SKU yang diisi di bawah."
                />

                <Card className="max-w-5xl">
                    <CardContent>
                        <ProductForm
                            action={ProductController.store.form()}
                            options={options}
                            submitLabel="Simpan Produk"
                            secondaryAction={
                                <Button variant="ghost" asChild>
                                    <Link href={ProductController.index()}>
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

ProductsCreate.layout = {
    breadcrumbs: [
        { title: 'Produk', href: productsIndex() },
        { title: 'Tambah', href: create() },
    ],
};
