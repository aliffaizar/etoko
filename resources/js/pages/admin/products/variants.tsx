import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { Info } from 'lucide-react';
import Heading from '@/components/heading';
import { ProductVariantsCard } from '@/components/admin/product-variants-card';
import { VariantGeneratorCard } from '@/components/admin/variant-generator-card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { edit, index as productsIndex } from '@/routes/admin/products';
import variantsRoute from '@/routes/admin/products/variants';
import type {
    ProductVariantAbilities,
    ProductVariantItem,
    ProductVariantLimits,
    ProductVariantScreenOptions,
    ProductVariantSummary,
} from '@/types';

type ProductVariantsProps = {
    product: ProductVariantSummary;
    variants: ProductVariantItem[];
    options: ProductVariantScreenOptions;
    can: ProductVariantAbilities;
    limits: ProductVariantLimits;
};

/**
 * The variant editor of one product (P1-E3-T6).
 *
 * A `simple` product keeps its single variant: the generator is hidden and the
 * notice points at the product form, where the type is decided (ADR-022).
 */
export default function ProductVariants({
    product,
    variants,
    options,
    can,
    limits,
}: ProductVariantsProps) {
    // The breadcrumb carries the product's name, so it comes from the page's
    // own props rather than a static `ProductVariants.layout` object.
    setLayoutProps({
        breadcrumbs: [
            { title: 'Produk', href: productsIndex() },
            { title: product.name, href: edit(product.id) },
            { title: 'Varian', href: variantsRoute.index(product.id) },
        ],
    });

    return (
        <>
            <Head title={`Varian · ${product.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={`Varian ${product.name}`}
                        description={`${product.type_label} · ${variants.length} varian`}
                    />
                    <Button variant="outline" asChild>
                        <Link href={edit(product.id)}>Kembali ke produk</Link>
                    </Button>
                </div>

                {!can.generate && (
                    <Alert className="max-w-5xl">
                        <Info aria-hidden />
                        <AlertTitle>Produk sederhana</AlertTitle>
                        <AlertDescription>
                            Produk sederhana dijual sebagai satu barang, jadi
                            varian tambahan tidak tersedia. Ubah tipe produk
                            menjadi “Produk Bervarian” di layar produk bila
                            barang ini punya pilihan warna atau ukuran.
                        </AlertDescription>
                    </Alert>
                )}

                {can.generate && (
                    <div className="max-w-5xl">
                        <VariantGeneratorCard
                            productId={product.id}
                            attributes={options.attributes}
                            limits={limits}
                        />
                    </div>
                )}

                <div className="max-w-5xl">
                    <ProductVariantsCard
                        product={product}
                        variants={variants}
                        attributes={options.attributes}
                        canUpdate={can.update}
                        canAdd={can.generate}
                    />
                </div>
            </div>
        </>
    );
}
