import { Head, Link, router } from '@inertiajs/react';
import { Minus, Plus, ShoppingCart, Trash2, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { VoucherBox } from '@/components/shop/voucher-box';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { formatRupiah } from '@/lib/format';
import cart from '@/routes/shop/cart';
import { index as catalogIndex } from '@/routes/shop/catalog';
import checkout from '@/routes/shop/checkout';
import type { BasketVoucher } from '@/types';

type CartVariant = {
    id: number;
    sku: string;
    name: string;
    product_name: string;
    product_slug: string;
    image_url: string | null;
    available_quantity: number | null;
};

type CartItemData = {
    id: number;
    quantity: number;
    unit_price_snapshot: number;
    line_total: number;
    notes: string | null;
    variant: CartVariant;
};

type CartData = {
    id: number;
    branch: { id: number; name: string } | null;
    subtotal: number;
    item_count: number;
    items: CartItemData[];
};

type CartProps = {
    cart: CartData;
    voucher: BasketVoucher | null;
};

export default function CartPage({ cart: cartData, voucher }: CartProps) {
    return (
        <>
            <Head title="Keranjang Belanja" />

            <div className="mx-auto max-w-5xl px-4 py-8">
                <h1 className="mb-6 flex items-center gap-2 text-2xl font-semibold">
                    <ShoppingCart className="size-6" />
                    Keranjang Belanja
                </h1>

                {cartData.items.length === 0 ? (
                    <EmptyCart />
                ) : (
                    <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
                        <CartItems items={cartData.items} />
                        <CartSummary cart={cartData} voucher={voucher} />
                    </div>
                )}
            </div>
        </>
    );
}

function EmptyCart() {
    return (
        <div className="flex flex-col items-center gap-4 py-16 text-center">
            <ShoppingCart className="size-12 text-muted-foreground" />
            <div>
                <p className="font-medium">Keranjang masih kosong</p>
                <p className="text-sm text-muted-foreground">
                    Temukan produk favorit Anda di toko kami.
                </p>
            </div>
            <Button asChild>
                <Link href={catalogIndex().url}>Belanja Sekarang</Link>
            </Button>
        </div>
    );
}

function CartItems({ items }: { items: CartItemData[] }) {
    return (
        <div className="rounded-lg border">
            <div className="divide-y">
                {items.map((item) => (
                    <CartItemRow key={item.id} item={item} />
                ))}
            </div>
        </div>
    );
}

function CartItemRow({ item }: { item: CartItemData }) {
    const [qty, setQty] = useState(item.quantity);
    const [updating, setUpdating] = useState(false);

    /*
     * The stepper moves first and asks the server after, so a refusal (stock
     * ran out between page load and click) would otherwise leave the input
     * showing a quantity the cart never accepted. The reloaded prop is the
     * truth.
     */
    useEffect(() => {
        setQty(item.quantity);
    }, [item.quantity]);

    const updateQty = (newQty: number) => {
        const clamped = Math.max(0, Math.min(99, newQty));
        setQty(clamped);
        setUpdating(true);

        router.patch(
            cart.update(item.id).url,
            { quantity: clamped },
            {
                preserveScroll: true,
                onFinish: () => setUpdating(false),
            },
        );
    };

    const remove = () => {
        router.delete(cart.destroy(item.id).url, { preserveScroll: true });
    };

    const isLow =
        item.variant.available_quantity !== null &&
        qty >= item.variant.available_quantity;

    return (
        <div className="flex gap-4 p-4">
            {/* Image */}
            <div className="size-20 shrink-0 overflow-hidden rounded-md border bg-muted">
                {item.variant.image_url ? (
                    <img
                        src={item.variant.image_url}
                        alt={item.variant.name}
                        className="size-full object-cover"
                    />
                ) : (
                    <div className="flex size-full items-center justify-center text-muted-foreground">
                        <ShoppingCart className="size-8" />
                    </div>
                )}
            </div>

            {/* Info */}
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <Link
                    href={`/produk/${item.variant.product_slug}`}
                    className="line-clamp-1 font-medium hover:underline"
                >
                    {item.variant.product_name}
                </Link>
                <span className="text-sm text-muted-foreground">
                    {item.variant.name}
                </span>
                <span className="text-sm font-medium">
                    {formatRupiah(item.unit_price_snapshot)}
                </span>

                {isLow && (
                    <p className="text-xs text-destructive">
                        Stok tersisa {item.variant.available_quantity} unit
                    </p>
                )}
            </div>

            {/* Qty controls */}
            <div className="flex shrink-0 flex-col items-end gap-2">
                <button
                    onClick={remove}
                    className="text-muted-foreground hover:text-destructive"
                    aria-label="Hapus item"
                >
                    <X className="size-4" />
                </button>

                <div className="flex items-center gap-1">
                    <Button
                        size="icon"
                        variant="outline"
                        className="size-7"
                        onClick={() => updateQty(qty - 1)}
                        disabled={updating || qty <= 1}
                        aria-label="Kurangi"
                    >
                        <Minus className="size-3" />
                    </Button>

                    <Input
                        type="number"
                        className="h-7 w-12 text-center"
                        value={qty}
                        min={1}
                        max={99}
                        onChange={(e) => setQty(parseInt(e.target.value) || 1)}
                        onBlur={() => updateQty(qty)}
                        disabled={updating}
                    />

                    <Button
                        size="icon"
                        variant="outline"
                        className="size-7"
                        onClick={() => updateQty(qty + 1)}
                        disabled={
                            updating ||
                            (item.variant.available_quantity !== null &&
                                qty >= item.variant.available_quantity)
                        }
                        aria-label="Tambah"
                    >
                        <Plus className="size-3" />
                    </Button>
                </div>

                <span className="text-sm font-semibold">
                    {formatRupiah(item.unit_price_snapshot * qty)}
                </span>
            </div>
        </div>
    );
}

function CartSummary({
    cart: cartData,
    voucher,
}: {
    cart: CartData;
    voucher: BasketVoucher | null;
}) {
    const discount = voucher?.is_accepted ? voucher.goods_discount : 0;

    return (
        <div className="h-fit rounded-lg border p-4">
            <h2 className="mb-4 font-semibold">Ringkasan Belanja</h2>

            <div className="flex flex-col gap-2 text-sm">
                <div className="flex justify-between">
                    <span className="text-muted-foreground">
                        Subtotal ({cartData.item_count} item)
                    </span>
                    <span>{formatRupiah(cartData.subtotal)}</span>
                </div>

                {discount > 0 && (
                    <div className="flex justify-between">
                        <span className="text-muted-foreground">
                            Voucher {voucher?.code}
                        </span>
                        <span className="tabular-nums">
                            −{formatRupiah(discount)}
                        </span>
                    </div>
                )}

                {cartData.branch && (
                    <div className="flex justify-between">
                        <span className="text-muted-foreground">Cabang</span>
                        <span>{cartData.branch.name}</span>
                    </div>
                )}
            </div>

            <div className="mt-4">
                <VoucherBox voucher={voucher} />
            </div>

            <Separator className="my-4" />

            <div className="flex justify-between font-semibold">
                <span>Total</span>
                <span>{formatRupiah(cartData.subtotal - discount)}</span>
            </div>

            <Button className="mt-4 w-full" asChild>
                <Link href={checkout.index().url}>Lanjut ke Checkout</Link>
            </Button>
            <p className="mt-2 text-center text-xs text-muted-foreground">
                {voucher?.is_accepted && voucher.discounts_shipping
                    ? 'Potongan ongkos kirim dihitung di halaman checkout.'
                    : 'Ongkos kirim dihitung di halaman checkout.'}
            </p>
        </div>
    );
}
