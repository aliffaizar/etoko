import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { ProductGallery } from '@/components/admin/product-gallery';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { edit, index as productsIndex } from '@/routes/admin/products';
import imagesRoute from '@/routes/admin/products/images';
import type {
    ProductImageItem,
    ProductImageLimits,
    ProductImageProductSummary,
    ProductImageScreenOptions,
} from '@/types';

type ProductImagesProps = {
    product: ProductImageProductSummary;
    images: ProductImageItem[];
    options: ProductImageScreenOptions;
    can: { update: boolean };
    limits: ProductImageLimits;
};

/** The photo gallery of one product (P1-E3-T7). */
export default function ProductImages({
    product,
    images,
    options,
    can,
    limits,
}: ProductImagesProps) {
    // The breadcrumb carries the product's name, so it comes from the page's
    // own props rather than a static `ProductImages.layout` object.
    setLayoutProps({
        breadcrumbs: [
            { title: 'Produk', href: productsIndex() },
            { title: product.name, href: edit(product.id) },
            { title: 'Foto', href: imagesRoute.index(product.id) },
        ],
    });

    return (
        <>
            <Head title={`Foto · ${product.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={`Foto ${product.name}`}
                        description={`${product.type_label} · ${images.length} foto`}
                    />
                    <Button variant="outline" asChild>
                        <Link href={edit(product.id)}>Kembali ke produk</Link>
                    </Button>
                </div>

                <div className="max-w-5xl">
                    <ProductGallery
                        productId={product.id}
                        images={images}
                        options={options}
                        limits={limits}
                        canUpdate={can.update}
                    />
                </div>
            </div>
        </>
    );
}
