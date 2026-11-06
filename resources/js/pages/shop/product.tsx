import { Head, Link, usePage } from '@inertiajs/react';
import {
    Info,
    MapPin,
    MessageSquare,
    PackageCheck,
    ShoppingCart,
    Star,
    Truck,
} from 'lucide-react';
import { useState } from 'react';
import { PriceTag } from '@/components/shop/price-tag';
import { ProductGallery } from '@/components/shop/product-gallery';
import { ShopEmpty } from '@/components/shop/shop-empty';
import { StockBadge } from '@/components/shop/stock-badge';
import {
    findVariant,
    initialSelection,
    VariantSelector,
} from '@/components/shop/variant-selector';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatNumber, formatWeight } from '@/lib/format';
import catalog from '@/routes/shop/catalog';
import type {
    ShopContext,
    ShopProductAttribute,
    ShopProductAvailability,
    ShopProductDetail,
    ShopProductImage,
    ShopProductVariant,
} from '@/types';

type Props = {
    product: ShopProductDetail;
    variants: ShopProductVariant[];
    images: ShopProductImage[];
    attributes: ShopProductAttribute[];
    breadcrumbs: Array<{ name: string; slug: string }>;
    branch_availability: ShopProductAvailability;
};

type DetailTab = 'description' | 'specification' | 'reviews';

/**
 * One product (P1-E5-T6, P1-E5-T7).
 *
 * The **price** is the catalogue's and the same at every branch (ADR-031); the
 * **stock** belongs to the branch in the header's picker (BR-2, BR-6). Both are
 * read from the selected variant rather than from the product, because picking
 * "XL" changes what it costs *and* whether this branch has one.
 *
 * A product the branch does not carry still renders, saying so — the catalogue
 * is company-wide, so pretending the product does not exist would be the wrong
 * answer to "does this shop sell it?".
 *
 * The buy button is deliberately disabled with an explanation: the cart
 * arrives in `P1-E6`, and a button that silently does nothing would be worse
 * than one that says why.
 */
export default function ShopProduct({
    product,
    variants,
    images,
    attributes,
    breadcrumbs,
    branch_availability: availability,
}: Props) {
    const shop = usePage().props.shop as ShopContext | undefined;
    const branch = shop?.branch ?? null;

    const [selection, setSelection] = useState<Record<number, number>>(() =>
        initialSelection(variants, attributes),
    );
    const [tab, setTab] = useState<DetailTab>('description');

    const selected = findVariant(variants, attributes, selection);

    return (
        <>
            <Head title={product.meta_title ?? product.name}>
                {product.meta_description && (
                    <meta
                        name="description"
                        content={product.meta_description}
                    />
                )}
            </Head>

            <div className="mx-auto flex max-w-7xl flex-col gap-8 px-4 py-8">
                <Breadcrumbs trail={breadcrumbs} productName={product.name} />

                <div className="grid gap-8 lg:grid-cols-2">
                    <ProductGallery
                        images={images}
                        productName={product.name}
                        selectedVariantId={selected?.id ?? null}
                    />

                    <div className="flex flex-col gap-5">
                        <header className="flex flex-col gap-2">
                            <Link
                                href={catalog.category(product.category_slug)}
                                className="text-xs text-muted-foreground hover:text-foreground"
                            >
                                {product.category_name}
                            </Link>
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {product.name}
                            </h1>
                            <ProductMeta product={product} />
                        </header>

                        <div className="flex flex-col gap-1">
                            <PriceTag
                                price={selected?.price ?? product.price_from}
                                compareAtPrice={selected?.compare_at_price}
                                hasRange={
                                    selected === null &&
                                    product.price_from !== product.price_to
                                }
                                size="lg"
                            />
                            <p className="text-xs text-muted-foreground">
                                {selected && isPromotionalPrice(selected)
                                    ? selected.price_source_label
                                    : 'Harga sama di semua cabang'}
                            </p>
                        </div>

                        {attributes.length > 0 && (
                            <VariantSelector
                                attributes={attributes}
                                variants={variants}
                                selection={selection}
                                onSelect={(attributeId, valueId) =>
                                    setSelection((current) => ({
                                        ...current,
                                        [attributeId]: valueId,
                                    }))
                                }
                            />
                        )}

                        <BranchStock
                            branchName={branch?.name ?? 'cabang terpilih'}
                            selected={selected}
                            availability={availability}
                        />

                        <BuyActions
                            selected={selected}
                            isSoldAtBranch={availability.is_sold_at_branch}
                        />

                        {branch && <FulfillmentNote branch={branch} />}
                    </div>
                </div>

                <Separator />

                <section className="flex flex-col gap-4">
                    <ToggleGroup
                        type="single"
                        value={tab}
                        onValueChange={(next) =>
                            next && setTab(next as DetailTab)
                        }
                        variant="outline"
                        className="self-start"
                    >
                        <ToggleGroupItem value="description">
                            Deskripsi
                        </ToggleGroupItem>
                        <ToggleGroupItem value="specification">
                            Spesifikasi
                        </ToggleGroupItem>
                        <ToggleGroupItem value="reviews">
                            Ulasan
                            {product.rating_count > 0
                                ? ` (${formatNumber(product.rating_count)})`
                                : ''}
                        </ToggleGroupItem>
                    </ToggleGroup>

                    {tab === 'description' && (
                        <DescriptionPanel product={product} />
                    )}
                    {tab === 'specification' && (
                        <SpecificationPanel
                            product={product}
                            selected={selected}
                        />
                    )}
                    {tab === 'reviews' && <ReviewsPanel />}
                </section>
            </div>
        </>
    );
}

function Breadcrumbs({
    trail,
    productName,
}: {
    trail: Array<{ name: string; slug: string }>;
    productName: string;
}) {
    return (
        <nav aria-label="Jejak navigasi">
            <ol className="flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
                <li className="flex items-center gap-1">
                    <Link
                        href={catalog.index()}
                        className="hover:text-foreground"
                    >
                        Semua produk
                    </Link>
                    <span aria-hidden>/</span>
                </li>
                {trail.map((node) => (
                    <li key={node.slug} className="flex items-center gap-1">
                        <Link
                            href={catalog.category(node.slug)}
                            className="hover:text-foreground"
                        >
                            {node.name}
                        </Link>
                        <span aria-hidden>/</span>
                    </li>
                ))}
                <li className="max-w-[16rem] truncate text-foreground">
                    {productName}
                </li>
            </ol>
        </nav>
    );
}

function ProductMeta({ product }: { product: ShopProductDetail }) {
    const parts: string[] = [];

    if (product.sold_count > 0) {
        parts.push(`${formatNumber(product.sold_count)} terjual`);
    }

    return (
        <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
            {product.rating_count > 0 ? (
                <span className="inline-flex items-center gap-1">
                    <Star
                        className="size-3.5 fill-amber-400 text-amber-400"
                        aria-hidden
                    />
                    <span className="font-medium text-foreground">
                        {product.rating_avg.toFixed(1)}
                    </span>
                    <span>
                        dari {formatNumber(product.rating_count)} ulasan
                    </span>
                </span>
            ) : (
                <span>Belum ada ulasan</span>
            )}
            {parts.map((part) => (
                <span key={part}>{part}</span>
            ))}
        </div>
    );
}

/**
 * What the chosen branch has, for the option on screen.
 *
 * Shows the whole product's figure until an option is picked, and the option's
 * own once it is — never a total across every branch, which would be a number
 * nobody can collect (BR-2).
 *
 * "Tidak dijual di cabang ini" and "stok habis" are kept apart on purpose: the
 * first is a standing decision by that branch, the second is a situation that
 * will change. Telling somebody to come back tomorrow for a product the branch
 * never carries would be a lie.
 */
function BranchStock({
    branchName,
    selected,
    availability,
}: {
    branchName: string;
    selected: ShopProductVariant | null;
    availability: ShopProductAvailability;
}) {
    const quantity = selected
        ? selected.available_quantity
        : availability.available_quantity;

    const isSold = availability.is_sold_at_branch;

    return (
        <div className="flex flex-col gap-2 rounded-lg border bg-muted/30 p-3">
            <div className="flex flex-wrap items-center gap-2">
                <MapPin className="size-4 text-primary" aria-hidden />
                <span className="text-sm font-medium">{branchName}</span>
                <StockBadge
                    availableQuantity={quantity}
                    isSoldAtBranch={isSold}
                />
            </div>

            <p className="text-xs text-muted-foreground">
                {!isSold
                    ? `${branchName} tidak menjual produk ini. Pilih cabang lain dari pemilih cabang di header untuk melihat ketersediaannya.`
                    : selected
                      ? quantity > 0
                          ? `${formatNumber(quantity)} unit siap dijual untuk varian ${selected.name} (SKU ${selected.sku}).`
                          : `Varian ${selected.name} sedang habis di cabang ini. Coba varian lain atau pilih cabang lain.`
                      : availability.in_stock_variant_count > 0
                        ? `${availability.in_stock_variant_count} dari ${availability.variant_count} varian tersedia di cabang ini. Pilih varian untuk melihat stoknya.`
                        : 'Belum ada varian produk ini yang tersedia di cabang ini.'}
            </p>

            {isSold && (
                <p className="text-xs text-muted-foreground">
                    Stok tersedia dihitung dari gudang cabang dikurangi yang
                    sedang ditahan checkout pembeli lain.
                </p>
            )}
        </div>
    );
}

/**
 * The buy buttons.
 *
 * Disabled with a reason until `P1-E6` builds the cart. Shown rather than
 * hidden because the page has to read as a product page, and because the
 * disabled state is where the stock rule becomes visible: an option with
 * nothing left could not be added even once the cart exists.
 */
function BuyActions({
    selected,
    isSoldAtBranch,
}: {
    selected: ShopProductVariant | null;
    isSoldAtBranch: boolean;
}) {
    const reason = !isSoldAtBranch
        ? 'Cabang terpilih tidak menjual produk ini. Pilih cabang lain di header.'
        : selected === null
          ? 'Pilih varian terlebih dahulu.'
          : !selected.is_in_stock
            ? 'Varian ini habis di cabang terpilih.'
            : 'Keranjang belanja hadir di tahap berikutnya.';

    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-wrap gap-2">
                <Button
                    size="lg"
                    disabled
                    className="flex-1"
                    aria-describedby="buy-reason"
                >
                    <ShoppingCart aria-hidden />
                    Tambah ke Keranjang
                </Button>
                <Button size="lg" variant="outline" disabled>
                    Beli Sekarang
                </Button>
            </div>
            <p
                id="buy-reason"
                className="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
            >
                <Info className="size-3.5 shrink-0" aria-hidden />
                {reason}
            </p>
        </div>
    );
}

function FulfillmentNote({
    branch,
}: {
    branch: NonNullable<ShopContext['branch']>;
}) {
    return (
        <ul className="flex flex-col gap-1.5 text-xs text-muted-foreground">
            {branch.supports_delivery && (
                <li className="inline-flex items-center gap-2">
                    <Truck className="size-3.5 shrink-0" aria-hidden />
                    Bisa dikirim dari {branch.name}
                </li>
            )}
            {branch.supports_pickup && (
                <li className="inline-flex items-center gap-2">
                    <PackageCheck className="size-3.5 shrink-0" aria-hidden />
                    Bisa diambil sendiri di {branch.name}
                </li>
            )}
        </ul>
    );
}

/**
 * The product's own copy.
 *
 * `description` is HTML written in the panel. It is rendered as text here on
 * purpose: `dangerouslySetInnerHTML` is forbidden until the content is
 * sanitised server side (docs/02-architecture.md §6), and that sanitiser is
 * not written yet — so the tags are shown stripped rather than executed.
 */
function DescriptionPanel({ product }: { product: ShopProductDetail }) {
    const text = stripTags(product.description ?? '');

    if (text === '' && product.short_description === null) {
        return (
            <ShopEmpty
                icon={Info}
                title="Belum ada deskripsi"
                description="Penjual belum menuliskan deskripsi untuk produk ini."
            />
        );
    }

    return (
        <div className="flex max-w-3xl flex-col gap-3 text-sm leading-relaxed">
            {product.short_description && (
                <p className="font-medium">{product.short_description}</p>
            )}
            {text !== '' && (
                <p className="whitespace-pre-line text-muted-foreground">
                    {text}
                </p>
            )}
        </div>
    );
}

function SpecificationPanel({
    product,
    selected,
}: {
    product: ShopProductDetail;
    selected: ShopProductVariant | null;
}) {
    const rows: Array<[string, string]> = [
        ['Berat', formatWeight(selected?.weight_gram ?? product.weight_gram)],
        ['Kategori', product.category_name],
    ];

    if (selected) {
        rows.unshift(['SKU', selected.sku]);
    }

    const dimensions = [product.length_cm, product.width_cm, product.height_cm];

    if (dimensions.every((side) => side !== null)) {
        rows.push([
            'Dimensi',
            `${dimensions[0]} × ${dimensions[1]} × ${dimensions[2]} cm`,
        ]);
    }

    return (
        <dl className="grid max-w-2xl gap-px overflow-hidden rounded-lg border bg-border sm:grid-cols-2">
            {rows.map(([label, value]) => (
                <div key={label} className="bg-card p-3">
                    <dt className="text-xs text-muted-foreground">{label}</dt>
                    <dd className="text-sm font-medium">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

/**
 * The reviews tab.
 *
 * Empty by design: reviews are their own table and their own moderation flow
 * in Phase 2 (`reviews.moderate`). The tab exists now because the product page
 * is where a shopper looks for them, and an absent tab reads as "this shop has
 * no reviews at all" (docs/05-roadmap.md P1-E5-T6).
 */
function ReviewsPanel() {
    return (
        <ShopEmpty
            icon={MessageSquare}
            title="Ulasan belum tersedia"
            description="Ulasan pelanggan akan muncul di sini setelah fitur ulasan aktif."
        />
    );
}

/**
 * Whether a price is worth explaining to the shopper.
 *
 * The storefront charges the catalogue price everywhere (ADR-031), so naming
 * `variant_price` or `product_base_price` tells a shopper nothing. A flash sale
 * or a promotion does — those are the sources `P1-E8` introduces, and they are
 * the reason the source travels with the price at all.
 */
function isPromotionalPrice(variant: ShopProductVariant): boolean {
    return (
        variant.price_source === 'flash_sale' ||
        variant.price_source === 'promotion'
    );
}

/** Strips HTML tags from panel-authored copy, so nothing is executed. */
function stripTags(html: string): string {
    return html
        .replace(/<[^>]*>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}
