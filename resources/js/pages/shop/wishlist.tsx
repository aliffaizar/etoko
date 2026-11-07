import { Head, Link, router } from '@inertiajs/react';
import { Heart, ShoppingCart, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { formatRupiah } from '@/lib/format';
import { index as catalogIndex } from '@/routes/shop/catalog';
import account from '@/routes/shop/account';
import cart from '@/routes/shop/cart';

type WishlistItem = {
    wishlist_id: number;
    product_id: number;
    name: string;
    slug: string;
    image_url: string | null;
    price: number | null;
    compare_at_price: number | null;
    is_in_stock: boolean;
    default_variant_id: number | null;
};

type WishlistProps = {
    items: WishlistItem[];
};

export default function WishlistPage({ items }: WishlistProps) {
    return (
        <>
            <Head title="Wishlist" />

            <div className="mx-auto max-w-5xl px-4 py-8">
                <h1 className="mb-6 flex items-center gap-2 text-2xl font-semibold">
                    <Heart className="size-6" />
                    Wishlist
                </h1>

                {items.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-12 text-center text-muted-foreground">
                        <Heart className="mx-auto mb-3 size-10" />
                        <p className="font-medium">Wishlist masih kosong</p>
                        <p className="mt-1 text-sm">
                            Simpan produk yang Anda sukai untuk membeli nanti.
                        </p>
                        <Button asChild variant="outline" className="mt-4">
                            <Link href={catalogIndex().url}>
                                Jelajahi Produk
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {items.map((item) => (
                            <WishlistCard key={item.wishlist_id} item={item} />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

function WishlistCard({ item }: { item: WishlistItem }) {
    const [adding, setAdding] = useState(false);

    const removeFromWishlist = () => {
        router.post(
            account.wishlist.toggle(item.product_id).url,
            {},
            { preserveScroll: true },
        );
    };

    const addToCart = () => {
        if (!item.default_variant_id) {
            return;
        }

        setAdding(true);
        router.post(
            cart.store().url,
            { product_variant_id: item.default_variant_id, quantity: 1 },
            {
                preserveScroll: true,
                onFinish: () => setAdding(false),
            },
        );
    };

    return (
        <div className="group flex flex-col overflow-hidden rounded-lg border">
            {/* Image */}
            <Link
                href={`/produk/${item.slug}`}
                className="relative overflow-hidden bg-muted"
            >
                <div className="aspect-square">
                    {item.image_url ? (
                        <img
                            src={item.image_url}
                            alt={item.name}
                            className="size-full object-cover transition-transform group-hover:scale-105"
                        />
                    ) : (
                        <div className="flex size-full items-center justify-center text-muted-foreground">
                            <ShoppingCart className="size-8" />
                        </div>
                    )}
                </div>

                {!item.is_in_stock && (
                    <div className="absolute inset-0 flex items-center justify-center bg-background/70">
                        <span className="text-sm font-medium text-muted-foreground">
                            Stok habis
                        </span>
                    </div>
                )}
            </Link>

            {/* Content */}
            <div className="flex flex-1 flex-col gap-2 p-3">
                <Link
                    href={`/produk/${item.slug}`}
                    className="line-clamp-2 text-sm font-medium hover:underline"
                >
                    {item.name}
                </Link>

                <div className="mt-auto">
                    {item.price ? (
                        <div className="flex items-baseline gap-1">
                            <span className="font-semibold">
                                {formatRupiah(item.price)}
                            </span>
                            {item.compare_at_price &&
                                item.compare_at_price > item.price && (
                                    <span className="text-xs text-muted-foreground line-through">
                                        {formatRupiah(item.compare_at_price)}
                                    </span>
                                )}
                        </div>
                    ) : (
                        <span className="text-sm text-muted-foreground">–</span>
                    )}
                </div>

                <div className="flex gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        className="flex-1"
                        onClick={addToCart}
                        disabled={
                            !item.is_in_stock ||
                            !item.default_variant_id ||
                            adding
                        }
                    >
                        <ShoppingCart className="size-3.5" />
                        {adding ? 'Menambah…' : 'Ke Keranjang'}
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={removeFromWishlist}
                        aria-label="Hapus dari wishlist"
                    >
                        <Trash2 className="size-3.5 text-destructive" />
                    </Button>
                </div>
            </div>
        </div>
    );
}
