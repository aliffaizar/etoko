import { Deferred, Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    PackageSearch,
    ShieldCheck,
    Store,
    Truck,
} from 'lucide-react';
import { ProductCard } from '@/components/shop/product-card';
import { ProductCardSkeleton } from '@/components/shop/product-card-skeleton';
import { ShopEmpty } from '@/components/shop/shop-empty';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import catalog from '@/routes/shop/catalog';
import type {
    ShopBanner,
    ShopCategoryTile,
    ShopContext,
    ShopProductCard,
} from '@/types';

type Props = {
    banners: ShopBanner[];
    categories: ShopCategoryTile[];
    featured_products: ShopProductCard[];
    /** Deferred: it sits below the fold, so the first paint does not wait. */
    latest_products?: ShopProductCard[];
};

const BANNER_TONES = {
    primary: 'bg-primary text-primary-foreground',
    accent: 'bg-accent text-accent-foreground',
    muted: 'bg-muted text-foreground',
} as const;

/**
 * The storefront's front page (P1-E5-T1).
 *
 * The rails are the company's catalogue at the company's prices (ADR-031);
 * only the stock badge on each card belongs to the branch in the header's
 * picker. The banners are static until the marketing epic in Phase 2 gives
 * them a table.
 */
export default function ShopHome({
    banners,
    categories,
    featured_products: featuredProducts,
    latest_products: latestProducts,
}: Props) {
    const shop = usePage().props.shop as ShopContext | undefined;
    const branchName = shop?.branch?.name ?? 'cabang terpilih';

    return (
        <>
            <Head title="Beranda" />

            <div className="mx-auto flex max-w-7xl flex-col gap-12 px-4 py-8">
                <BannerRail banners={banners} />

                <PromiseRow branchName={branchName} />

                <CategoryGrid categories={categories} />

                <ProductSection
                    title="Produk unggulan"
                    description="Pilihan terbaik dari katalog kami."
                    products={featuredProducts}
                    emptyTitle="Belum ada produk unggulan"
                    emptyDescription="Belum ada produk yang ditandai unggulan. Telusuri katalog untuk melihat semuanya."
                />

                <section className="flex flex-col gap-4">
                    <SectionHeading
                        title="Produk terbaru"
                        description="Baru masuk katalog."
                    />

                    <Deferred
                        data="latest_products"
                        fallback={
                            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                                <ProductCardSkeleton count={4} />
                            </div>
                        }
                    >
                        {latestProducts && latestProducts.length > 0 ? (
                            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                                {latestProducts.map((product) => (
                                    <ProductCard
                                        key={product.id}
                                        product={product}
                                    />
                                ))}
                            </div>
                        ) : (
                            <ShopEmpty
                                icon={PackageSearch}
                                title="Katalog masih kosong"
                                description="Belum ada produk yang terbit di katalog."
                            />
                        )}
                    </Deferred>
                </section>
            </div>
        </>
    );
}

function BannerRail({ banners }: { banners: ShopBanner[] }) {
    if (banners.length === 0) {
        return null;
    }

    const [lead, ...rest] = banners;

    return (
        <section aria-label="Promosi" className="grid gap-4 lg:grid-cols-3">
            <article
                className={cn(
                    'flex flex-col justify-end gap-3 rounded-2xl p-8 lg:col-span-2',
                    BANNER_TONES[lead.tone],
                )}
            >
                <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">
                    {lead.title}
                </h1>
                <p className="max-w-lg text-sm opacity-90">{lead.subtitle}</p>
                <Button
                    variant="secondary"
                    size="sm"
                    className="self-start"
                    asChild
                >
                    <Link href={catalog.index()} prefetch>
                        {lead.cta_label}
                        <ArrowRight aria-hidden />
                    </Link>
                </Button>
            </article>

            <div className="grid gap-4">
                {rest.map((banner) => (
                    <article
                        key={banner.title}
                        className={cn(
                            'flex flex-col justify-end gap-2 rounded-2xl p-6',
                            BANNER_TONES[banner.tone],
                        )}
                    >
                        <h2 className="text-base font-semibold">
                            {banner.title}
                        </h2>
                        <p className="text-xs opacity-90">{banner.subtitle}</p>
                        <Link
                            href={catalog.index()}
                            className="text-xs font-medium underline-offset-4 hover:underline"
                        >
                            {banner.cta_label}
                        </Link>
                    </article>
                ))}
            </div>
        </section>
    );
}

/**
 * What this shop promises, stated in terms of how it actually works rather
 * than as generic marketing: one catalogue at one price, and a branch that
 * decides whether you can have it today (ADR-031).
 */
function PromiseRow({ branchName }: { branchName: string }) {
    const promises = [
        {
            icon: Store,
            title: 'Satu harga di semua cabang',
            body: 'Harga yang kamu lihat berlaku di seluruh cabang, tanpa kejutan di kasir.',
        },
        {
            icon: ShieldCheck,
            title: 'Stok yang nyata',
            body: `Ketersediaan dihitung dari gudang ${branchName}, bukan perkiraan.`,
        },
        {
            icon: Truck,
            title: 'Kirim atau ambil sendiri',
            body: 'Pilih dikirim ke alamatmu, atau ambil langsung di cabang.',
        },
    ];

    return (
        <section className="grid gap-4 sm:grid-cols-3">
            {promises.map((promise) => (
                <div
                    key={promise.title}
                    className="flex items-start gap-3 rounded-xl border p-4"
                >
                    <promise.icon
                        className="mt-0.5 size-5 shrink-0 text-primary"
                        aria-hidden
                    />
                    <div className="min-w-0">
                        <h2 className="text-sm font-medium">{promise.title}</h2>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {promise.body}
                        </p>
                    </div>
                </div>
            ))}
        </section>
    );
}

function CategoryGrid({ categories }: { categories: ShopCategoryTile[] }) {
    if (categories.length === 0) {
        return null;
    }

    return (
        <section className="flex flex-col gap-4">
            <SectionHeading
                title="Kategori unggulan"
                description="Mulai dari kategori utama katalog."
            />

            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                {categories.map((category) => (
                    <Link
                        key={category.slug}
                        href={catalog.category(category.slug)}
                        prefetch
                        className="group flex flex-col gap-2 overflow-hidden rounded-xl border bg-card p-4 transition-colors hover:border-primary/40"
                    >
                        {category.image_url ? (
                            <img
                                src={category.image_url}
                                alt=""
                                loading="lazy"
                                className="h-24 w-full rounded-lg object-cover"
                            />
                        ) : (
                            <div className="flex h-24 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                <Store className="size-7" aria-hidden />
                            </div>
                        )}

                        <h3 className="text-sm font-medium">{category.name}</h3>

                        {category.child_names.length > 0 && (
                            <p className="line-clamp-2 text-xs text-muted-foreground">
                                {category.child_names.join(' · ')}
                            </p>
                        )}
                    </Link>
                ))}
            </div>
        </section>
    );
}

function ProductSection({
    title,
    description,
    products,
    emptyTitle,
    emptyDescription,
}: {
    title: string;
    description: string;
    products: ShopProductCard[];
    emptyTitle: string;
    emptyDescription: string;
}) {
    return (
        <section className="flex flex-col gap-4">
            <SectionHeading title={title} description={description} />

            {products.length === 0 ? (
                <ShopEmpty
                    icon={PackageSearch}
                    title={emptyTitle}
                    description={emptyDescription}
                />
            ) : (
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                    {products.map((product) => (
                        <ProductCard key={product.id} product={product} />
                    ))}
                </div>
            )}
        </section>
    );
}

function SectionHeading({
    title,
    description,
}: {
    title: string;
    description: string;
}) {
    return (
        <div className="flex flex-wrap items-end justify-between gap-2">
            <div>
                <h2 className="text-lg font-semibold tracking-tight">
                    {title}
                </h2>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>
            <Button variant="ghost" size="sm" asChild>
                <Link href={catalog.index()} prefetch>
                    Lihat semua
                    <ArrowRight aria-hidden />
                </Link>
            </Button>
        </div>
    );
}
