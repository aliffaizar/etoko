import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Clock,
    MapPin,
    Package,
    Plus,
    ShoppingBag,
    Store,
    Truck,
} from 'lucide-react';
import { useState } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { formatRupiah, formatWeight } from '@/lib/format';
import account from '@/routes/shop/account';
import checkout from '@/routes/shop/checkout';
import { index as cartIndex } from '@/routes/shop/cart';
import type { FulfillmentType } from '@/types/order';

type CheckoutItem = {
    id: number;
    quantity: number;
    unit_price: number;
    line_total: number;
    sku: string;
    name: string;
    product_name: string;
    product_slug: string;
    image_url: string | null;
    available_quantity: number;
};

type CheckoutAddress = {
    id: number;
    label: string;
    recipient_name: string;
    phone: string;
    address_line: string;
    district_name: string;
    city_name: string;
    province_name: string;
    postal_code: string;
    is_default: boolean;
};

type CheckoutProps = {
    cart: {
        subtotal: number;
        item_count: number;
        total_weight_gram: number;
        items: CheckoutItem[];
    };
    branch: {
        id: number;
        name: string;
        city_name: string | null;
        address_line: string;
        phone: string | null;
        supports_delivery: boolean;
        supports_pickup: boolean;
        fulfillment_options: { value: FulfillmentType; label: string }[];
    };
    addresses: CheckoutAddress[];
    costs: { flat_shipping_cost: number; tax_percent: number };
    reservation_minutes: number;
};

type CheckoutForm = {
    fulfillment_type: FulfillmentType;
    address_id: number | null;
    customer_note: string;
    [key: string]: FulfillmentType | number | null | string;
};

const FULFILLMENT_ICON = { delivery: Truck, pickup: Store } as const;

export default function Checkout({
    cart,
    branch,
    addresses,
    costs,
    reservation_minutes: reservationMinutes,
}: CheckoutProps) {
    const firstOption = branch.fulfillment_options[0]?.value ?? 'delivery';
    const defaultAddress =
        addresses.find((address) => address.is_default) ?? addresses[0] ?? null;

    const form = useForm<CheckoutForm>({
        fulfillment_type: firstOption,
        address_id: firstOption === 'delivery' ? (defaultAddress?.id ?? null) : null,
        customer_note: '',
    });

    const [shortLines] = useState(() =>
        cart.items.filter((item) => item.quantity > item.available_quantity),
    );

    const isPickup = form.data.fulfillment_type === 'pickup';
    const shippingCost = isPickup ? 0 : costs.flat_shipping_cost;
    const tax = Math.round((cart.subtotal * costs.tax_percent) / 100);
    const grandTotal = cart.subtotal + shippingCost + tax;

    const chooseFulfillment = (value: FulfillmentType) => {
        form.setData({
            ...form.data,
            fulfillment_type: value,
            address_id: value === 'pickup' ? null : (defaultAddress?.id ?? null),
        });
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(checkout.store().url, { preserveScroll: true });
    };

    const needsAddress = !isPickup && addresses.length === 0;

    return (
        <>
            <Head title="Checkout" />

            <form
                onSubmit={submit}
                className="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-8"
            >
                <div>
                    <h1 className="text-2xl font-semibold">Checkout</h1>
                    <p className="text-sm text-muted-foreground">
                        Periksa pesanan Anda, lalu konfirmasi. Stok akan ditahan{' '}
                        {reservationMinutes} menit untuk Anda selesaikan
                        pembayarannya.
                    </p>
                </div>

                {form.errors.checkout && (
                    <Alert variant="destructive">
                        <AlertTriangle aria-hidden />
                        <AlertTitle>Pesanan belum bisa dibuat</AlertTitle>
                        <AlertDescription>
                            <p>{form.errors.checkout}</p>
                            {form.errors.items && <p>{form.errors.items}</p>}
                            <Button variant="outline" size="sm" asChild>
                                <Link href={cartIndex().url}>
                                    Buka keranjang
                                </Link>
                            </Button>
                        </AlertDescription>
                    </Alert>
                )}

                {shortLines.length > 0 && (
                    <Alert variant="destructive">
                        <AlertTriangle aria-hidden />
                        <AlertTitle>Stok di cabang ini tidak cukup</AlertTitle>
                        <AlertDescription>
                            <p>
                                {shortLines
                                    .map(
                                        (item) =>
                                            `${item.product_name} (tersisa ${item.available_quantity})`,
                                    )
                                    .join(', ')}
                                . Kurangi jumlahnya di keranjang atau pilih
                                cabang lain.
                            </p>
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-6 lg:grid-cols-[1fr_22rem]">
                    <div className="flex flex-col gap-6">
                        <section className="rounded-lg border p-4">
                            <h2 className="mb-3 text-sm font-semibold">
                                Cara pemenuhan
                            </h2>

                            <div className="grid gap-3 sm:grid-cols-2">
                                {branch.fulfillment_options.map((option) => {
                                    const Icon =
                                        FULFILLMENT_ICON[option.value];
                                    const selected =
                                        form.data.fulfillment_type ===
                                        option.value;

                                    return (
                                        <button
                                            key={option.value}
                                            type="button"
                                            aria-pressed={selected}
                                            onClick={() =>
                                                chooseFulfillment(option.value)
                                            }
                                            className={cn(
                                                'flex items-start gap-3 rounded-lg border p-3 text-left transition-colors',
                                                selected
                                                    ? 'border-primary bg-primary/5'
                                                    : 'hover:bg-accent',
                                            )}
                                        >
                                            <Icon
                                                aria-hidden
                                                className="mt-0.5 size-5 shrink-0 text-muted-foreground"
                                            />
                                            <span className="min-w-0">
                                                <span className="block font-medium">
                                                    {option.label}
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {option.value === 'pickup'
                                                        ? `Ambil di ${branch.name}, tanpa biaya kirim`
                                                        : `Ongkos kirim ${formatRupiah(costs.flat_shipping_cost)}`}
                                                </span>
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>

                            {form.errors.fulfillment_type && (
                                <p className="mt-2 text-sm text-destructive">
                                    {form.errors.fulfillment_type}
                                </p>
                            )}
                        </section>

                        {isPickup ? (
                            <section className="rounded-lg border p-4">
                                <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold">
                                    <Store aria-hidden className="size-4" />
                                    Diambil di cabang
                                </h2>
                                <p className="font-medium">{branch.name}</p>
                                <p className="text-sm text-muted-foreground">
                                    {branch.address_line}
                                    {branch.city_name
                                        ? `, ${branch.city_name}`
                                        : ''}
                                </p>
                                {branch.phone && (
                                    <p className="text-sm text-muted-foreground">
                                        Telepon {branch.phone}
                                    </p>
                                )}
                            </section>
                        ) : (
                            <section className="rounded-lg border p-4">
                                <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold">
                                    <MapPin aria-hidden className="size-4" />
                                    Alamat pengiriman
                                </h2>

                                {needsAddress ? (
                                    <div className="flex flex-col items-start gap-3">
                                        <p className="text-sm text-muted-foreground">
                                            Anda belum punya alamat tersimpan.
                                            Tambahkan satu untuk melanjutkan.
                                        </p>
                                        <Button variant="outline" asChild>
                                            <Link
                                                href={
                                                    account.addresses.index()
                                                        .url
                                                }
                                            >
                                                <Plus aria-hidden />
                                                Tambah alamat
                                            </Link>
                                        </Button>
                                    </div>
                                ) : (
                                    <ul className="flex flex-col gap-2">
                                        {addresses.map((address) => (
                                            <li key={address.id}>
                                                <label
                                                    className={cn(
                                                        'flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors',
                                                        form.data
                                                            .address_id ===
                                                            address.id
                                                            ? 'border-primary bg-primary/5'
                                                            : 'hover:bg-accent',
                                                    )}
                                                >
                                                    <input
                                                        type="radio"
                                                        name="address_id"
                                                        className="mt-1 size-4 accent-primary"
                                                        checked={
                                                            form.data
                                                                .address_id ===
                                                            address.id
                                                        }
                                                        onChange={() =>
                                                            form.setData(
                                                                'address_id',
                                                                address.id,
                                                            )
                                                        }
                                                    />
                                                    <span className="min-w-0 text-sm">
                                                        <span className="block font-medium">
                                                            {address.label} ·{' '}
                                                            {
                                                                address.recipient_name
                                                            }
                                                        </span>
                                                        <span className="block text-muted-foreground">
                                                            {
                                                                address.address_line
                                                            }
                                                            ,{' '}
                                                            {
                                                                address.district_name
                                                            }
                                                            , {address.city_name}
                                                            ,{' '}
                                                            {
                                                                address.province_name
                                                            }{' '}
                                                            {
                                                                address.postal_code
                                                            }
                                                        </span>
                                                        <span className="block text-muted-foreground">
                                                            {address.phone}
                                                        </span>
                                                    </span>
                                                </label>
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                {form.errors.address_id && (
                                    <p className="mt-2 text-sm text-destructive">
                                        {form.errors.address_id}
                                    </p>
                                )}
                            </section>
                        )}

                        <section className="rounded-lg border p-4">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold">
                                <Package aria-hidden className="size-4" />
                                {cart.item_count} item dari {branch.name}
                            </h2>

                            <ul className="divide-y">
                                {cart.items.map((item) => (
                                    <li
                                        key={item.id}
                                        className="flex gap-3 py-3 first:pt-0 last:pb-0"
                                    >
                                        <div className="size-14 shrink-0 overflow-hidden rounded-md border bg-muted">
                                            {item.image_url ? (
                                                <img
                                                    src={item.image_url}
                                                    alt=""
                                                    className="size-full object-cover"
                                                />
                                            ) : (
                                                <div className="flex size-full items-center justify-center">
                                                    <ShoppingBag
                                                        aria-hidden
                                                        className="size-5 text-muted-foreground"
                                                    />
                                                </div>
                                            )}
                                        </div>

                                        <div className="min-w-0 flex-1 text-sm">
                                            <p className="truncate font-medium">
                                                {item.product_name}
                                            </p>
                                            <p className="truncate text-muted-foreground">
                                                {item.name} · {item.sku}
                                            </p>
                                            <p className="text-muted-foreground">
                                                {item.quantity} ×{' '}
                                                {formatRupiah(item.unit_price)}
                                            </p>
                                        </div>

                                        <p className="shrink-0 text-sm font-medium tabular-nums">
                                            {formatRupiah(item.line_total)}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        </section>

                        <section className="rounded-lg border p-4">
                            <Label
                                htmlFor="customer_note"
                                className="text-sm font-semibold"
                            >
                                Catatan untuk cabang (opsional)
                            </Label>
                            <Textarea
                                id="customer_note"
                                className="mt-2"
                                rows={3}
                                maxLength={500}
                                placeholder="Mis. titip di pos keamanan, atau minta dibungkus kado."
                                value={form.data.customer_note}
                                onChange={(event) =>
                                    form.setData(
                                        'customer_note',
                                        event.target.value,
                                    )
                                }
                            />
                            {form.errors.customer_note && (
                                <p className="mt-2 text-sm text-destructive">
                                    {form.errors.customer_note}
                                </p>
                            )}
                        </section>
                    </div>

                    <aside className="h-fit rounded-lg border p-4 lg:sticky lg:top-24">
                        <h2 className="mb-4 font-semibold">Ringkasan</h2>

                        <dl className="flex flex-col gap-2 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Subtotal ({cart.item_count} item)
                                </dt>
                                <dd className="tabular-nums">
                                    {formatRupiah(cart.subtotal)}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Ongkos kirim
                                </dt>
                                <dd className="tabular-nums">
                                    {isPickup
                                        ? 'Gratis (ambil sendiri)'
                                        : formatRupiah(shippingCost)}
                                </dd>
                            </div>
                            {costs.tax_percent > 0 && (
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">
                                        Pajak ({costs.tax_percent}%)
                                    </dt>
                                    <dd className="tabular-nums">
                                        {formatRupiah(tax)}
                                    </dd>
                                </div>
                            )}
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Berat total
                                </dt>
                                <dd className="tabular-nums">
                                    {formatWeight(cart.total_weight_gram)}
                                </dd>
                            </div>
                        </dl>

                        <Separator className="my-4" />

                        <div className="flex items-baseline justify-between gap-4">
                            <span className="font-semibold">Total bayar</span>
                            <span className="text-lg font-semibold tabular-nums">
                                {formatRupiah(grandTotal)}
                            </span>
                        </div>

                        <Button
                            type="submit"
                            className="mt-4 w-full"
                            disabled={
                                form.processing ||
                                needsAddress ||
                                shortLines.length > 0
                            }
                        >
                            {form.processing
                                ? 'Memproses…'
                                : 'Buat Pesanan'}
                        </Button>

                        <p className="mt-3 flex items-start gap-2 text-xs text-muted-foreground">
                            <Clock aria-hidden className="mt-0.5 size-3.5 shrink-0" />
                            Pembayaran manual: setelah pesanan dibuat, cabang
                            akan menghubungi Anda untuk konfirmasi. Pembayaran
                            online hadir pada tahap berikutnya.
                        </p>
                    </aside>
                </div>
            </form>
        </>
    );
}
