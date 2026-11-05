import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { Boxes, Images, Star } from 'lucide-react';
import ProductController from '@/actions/App/Http/Controllers/Admin/ProductController';
import Heading from '@/components/heading';
import { ProductForm } from '@/components/admin/product-form';
import { StatusBadge, type StatusTone } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatDateTime } from '@/lib/format';
import { edit, index as productsIndex } from '@/routes/admin/products';
import images from '@/routes/admin/products/images';
import variants from '@/routes/admin/products/variants';
import type {
    ProductAbilities,
    ProductDetail,
    ProductFormOptions,
    ProductPublicationState,
} from '@/types';

type ProductsEditProps = {
    product: ProductDetail;
    options: ProductFormOptions;
    can: ProductAbilities;
};

const STATE_TONE: Record<ProductPublicationState, StatusTone> = {
    published: 'success',
    scheduled: 'info',
    draft: 'neutral',
    inactive: 'danger',
};

export default function ProductsEdit({
    product,
    options,
    can,
}: ProductsEditProps) {
    // The breadcrumb carries the product's name, so it comes from the page's
    // own props rather than a static `ProductsEdit.layout` object.
    setLayoutProps({
        breadcrumbs: [
            { title: 'Produk', href: productsIndex() },
            { title: product.name, href: edit(product.id) },
        ],
    });

    return (
        <>
            <Head title={product.name} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={product.name}
                        description={`${product.sku} · ${product.category_name} · ${product.variant_count} varian · Dibuat ${formatDateTime(product.created_at)}`}
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        <Button variant="outline" asChild>
                            <Link href={variants.index(product.id)}>
                                <Boxes aria-hidden />
                                Varian ({product.variant_count})
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={images.index(product.id)}>
                                <Images aria-hidden />
                                Foto
                            </Link>
                        </Button>
                        {product.is_featured && (
                            <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                <Star
                                    aria-hidden
                                    className="size-3.5 fill-amber-400 text-amber-500"
                                />
                                Unggulan
                            </span>
                        )}
                        <StatusBadge
                            label={product.state_label}
                            tone={STATE_TONE[product.state]}
                        />
                    </div>
                </div>

                <Card className="max-w-5xl">
                    <CardHeader>
                        <CardTitle>Data produk</CardTitle>
                        <CardDescription>
                            Harga dan berat di sini berlaku untuk semua cabang,
                            dan menjadi acuan setiap varian yang tidak mengisi
                            angkanya sendiri. Varian dan galeri foto punya
                            layarnya sendiri pula. Ketersediaan dan harga khusus
                            per cabang menyusul di tahap berikutnya.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ProductForm
                            action={ProductController.update.form(product.id)}
                            options={options}
                            product={product}
                            submitLabel="Simpan Perubahan"
                            secondaryAction={
                                <Button variant="ghost" asChild>
                                    <Link href={ProductController.index()}>
                                        Kembali ke daftar
                                    </Link>
                                </Button>
                            }
                        />
                    </CardContent>
                </Card>

                {can.delete && (
                    <Card className="max-w-5xl border-destructive/40">
                        <CardHeader>
                            <CardTitle>Hapus produk</CardTitle>
                            <CardDescription>
                                Produk dihapus secara lunak: varian, stok, dan
                                riwayat pesanannya tetap tersimpan, tetapi
                                produk tidak lagi muncul di storefront maupun
                                katalog panel.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Button variant="destructive" asChild>
                                <Link
                                    href={ProductController.destroy(product.id)}
                                    as="button"
                                >
                                    Hapus produk
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}
