import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Clock,
    MapPin,
    Package,
    Plus,
    ShoppingBag,
    Store,
    Truck,
    Wallet,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { PaymentMethodPicker } from '@/components/shop/payment-method-picker';
import { ShippingOptionPicker } from '@/components/shop/shipping-option-picker';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { formatDuration, formatRupiah, formatWeight } from '@/lib/format';
import account from '@/routes/shop/account';
import checkout from '@/routes/shop/checkout';
import { index as cartIndex } from '@/routes/shop/cart';
import type { FulfillmentType } from '@/types/order';
import type { CheckoutPaymentMethods } from '@/types/payment';
import type { CheckoutShipping } from '@/types/shipping';

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
    /**
     * The couriers that will carry this cart to the chosen address, quoted
     * server-side (P2-E2-T3). Reloaded on its own when the address changes,
     * because a courier's price depends on where the parcel is going and
     * nothing else on this screen does.
     */
    shipping: CheckoutShipping;
    /**
     * Quoted for both ways of receiving the goods, because the customer has
     * not chosen one yet and COD depends on it (P2-E1-T7).
     */
    payment_methods: CheckoutPaymentMethods;
    reservation_minutes: number;
};

type CheckoutForm = {
    fulfillment_type: FulfillmentType;
    address_id: number | null;
    courier_service_id: number | null;
    payment_method_id: number | null;
    customer_note: string;
    [key: string]: FulfillmentType | number | null | string;
};

const FULFILLMENT_ICON = { delivery: Truck, pickup: Store } as const;

export default function Checkout({
    cart,
    branch,
    addresses,
    costs,
    shipping,
    payment_methods: paymentMethods,
    reservation_minutes: reservationMinutes,
}: CheckoutProps) {
    const firstOption = branch.fulfillment_options[0]?.value ?? 'delivery';
    const defaultAddress =
        addresses.find((address) => address.is_default) ?? addresses[0] ?? null;

    /** The first method this fulfilment can actually use, or nothing. */
    const firstEligible = (fulfillment: FulfillmentType) =>
        paymentMethods[fulfillment].find((method) => method.is_eligible)?.id ??
        null;

    /** The cheapest courier on offer, which is the one to pre-select. */
    const cheapestOption = shipping.options[0] ?? null;

    const form = useForm<CheckoutForm>({
        fulfillment_type: firstOption,
        address_id:
            firstOption === 'delivery' ? (defaultAddress?.id ?? null) : null,
        courier_service_id:
            firstOption === 'delivery'
                ? (cheapestOption?.courier_service_id ?? null)
                : null,
        payment_method_id: firstEligible(firstOption),
        customer_note: '',
    });

    const [shortLines] = useState(() =>
        cart.items.filter((item) => item.quantity > item.available_quantity),
    );

    const isPickup = form.data.fulfillment_type === 'pickup';

    const selectedOption =
        shipping.options.find(
            (option) =>
                option.courier_service_id === form.data.courier_service_id,
        ) ?? null;

    /**
     * The delivery charge is the chosen courier's own quote.
     *
     * Nothing is charged for a pickup, and an address nobody serves shows the
     * flat figure the shop falls back to — the same one `PlaceOrder` would
     * bill for a delivery arranged by hand. Either way the figure here is one
     * the server produced; the browser never sends a price back.
     */
    const shippingCost = isPickup
        ? 0
        : (selectedOption?.cost ?? costs.flat_shipping_cost);

    const tax = Math.round((cart.subtotal * costs.tax_percent) / 100);
    const orderTotal = cart.subtotal + shippingCost + tax;

    const methodsForFulfillment = paymentMethods[form.data.fulfillment_type];
    const selectedMethod = methodsForFulfillment.find(
        (method) => method.id === form.data.payment_method_id,
    );
    const paymentFee = selectedMethod?.fee_amount ?? 0;

    /**
     * Keep the courier selection on an option that still exists.
     *
     * The options are re-quoted when the address changes, and the service that
     * was selected may not be among the new ones — a courier that reaches one
     * city need not reach another. Falling back to the cheapest leaves the
     * screen with a price beside the button rather than an empty selection the
     * server would refuse.
     */
    useEffect(() => {
        if (isPickup || selectedOption !== null) {
            return;
        }

        if (cheapestOption !== null) {
            form.setData(
                'courier_service_id',
                cheapestOption.courier_service_id,
            );
        }
    }, [isPickup, selectedOption, cheapestOption, form]);

    /**
     * Switching how the goods arrive re-decides how they can be paid for: COD
     * is delivery-only, so a method chosen for one may be ineligible for the
     * other. Rather than leave an invalid choice selected and let the server
     * refuse it, the selection falls back to the first method the new
     * fulfilment can use.
     */
    const chooseFulfillment = (value: FulfillmentType) => {
        const stillEligible = paymentMethods[value].some(
            (method) =>
                method.id === form.data.payment_method_id && method.is_eligible,
        );

        form.setData({
            ...form.data,
            fulfillment_type: value,
            address_id:
                value === 'pickup' ? null : (defaultAddress?.id ?? null),
            // Nothing is sent on a pickup, so there is no courier to choose;
            // switching back picks the cheapest again.
            courier_service_id:
                value === 'pickup'
                    ? null
                    : (cheapestOption?.courier_service_id ?? null),
            payment_method_id: stillEligible
                ? form.data.payment_method_id
                : firstEligible(value),
        });
    };

    /**
     * Switching address re-quotes the couriers, because the price depends on
     * where the parcel is going.
     *
     * A partial reload of `shipping` and `payment_methods` only: the cart, the
     * address list and the branch have not changed, and re-rendering them
     * would throw away what the customer has typed in the note field. The
     * chosen courier is cleared rather than kept — a service priced for one
     * city is not the same offer in another, and `PlaceOrder` would re-quote
     * it anyway.
     */
    const chooseAddress = (addressId: number) => {
        form.setData({
            ...form.data,
            address_id: addressId,
            // Cleared so no stale price is shown while the new quotes land;
            // the effect above picks the cheapest of whatever comes back.
            courier_service_id: null,
        });

        router.reload({
            data: { alamat: addressId },
            only: ['shipping', 'payment_methods'],
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
                        {formatDuration(reservationMinutes)} untuk Anda
                        selesaikan pembayarannya.
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
                                    const Icon = FULFILLMENT_ICON[option.value];
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
                                                        : cheapestOption
                                                          ? `Ongkos kirim mulai ${formatRupiah(cheapestOption.cost)}`
                                                          : 'Pilih alamat untuk melihat ongkos kirim'}
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
                                                        form.data.address_id ===
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
                                                            chooseAddress(
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
                                                            ,{' '}
                                                            {address.city_name},{' '}
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

                        {!isPickup && (
                            <section className="rounded-lg border p-4">
                                <h2 className="mb-1 flex items-center gap-2 text-sm font-semibold">
                                    <Truck aria-hidden className="size-4" />
                                    Kurir & ongkos kirim
                                </h2>
                                <p className="mb-3 text-xs text-muted-foreground">
                                    Ongkos kirim dihitung dari berat pesanan (
                                    {formatWeight(cart.total_weight_gram)}) dan
                                    alamat tujuan.
                                </p>

                                <ShippingOptionPicker
                                    options={shipping.options}
                                    unavailableReason={
                                        shipping.unavailable_reason
                                    }
                                    selectedId={form.data.courier_service_id}
                                    onSelect={(id) =>
                                        form.setData('courier_service_id', id)
                                    }
                                    hasAddress={form.data.address_id !== null}
                                    error={form.errors.courier_service_id}
                                />
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
                            <h2 className="mb-1 flex items-center gap-2 text-sm font-semibold">
                                <Wallet aria-hidden className="size-4" />
                                Metode pembayaran
                            </h2>
                            <p className="mb-3 text-xs text-muted-foreground">
                                Pilih cara pembayaran. Instruksinya muncul di
                                halaman pesanan setelah pesanan dibuat.
                            </p>

                            <PaymentMethodPicker
                                methods={methodsForFulfillment}
                                selectedId={form.data.payment_method_id}
                                onSelect={(id) =>
                                    form.setData('payment_method_id', id)
                                }
                                error={form.errors.payment_method_id}
                            />
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
                                    {isPickup || selectedOption === null
                                        ? 'Ongkos kirim'
                                        : `Ongkos kirim · ${selectedOption.display_name}`}
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
                            {paymentFee > 0 && (
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">
                                        Biaya {selectedMethod?.name}
                                    </dt>
                                    <dd className="tabular-nums">
                                        {formatRupiah(paymentFee)}
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
                                {formatRupiah(orderTotal + paymentFee)}
                            </span>
                        </div>

                        <Button
                            type="submit"
                            className="mt-4 w-full"
                            disabled={
                                form.processing ||
                                needsAddress ||
                                shortLines.length > 0 ||
                                form.data.payment_method_id === null ||
                                // A delivery with no courier chosen is a
                                // checkout the server will refuse.
                                (!isPickup &&
                                    form.data.courier_service_id === null)
                            }
                        >
                            {form.processing ? 'Memproses…' : 'Buat Pesanan'}
                        </Button>

                        <p className="mt-3 flex items-start gap-2 text-xs text-muted-foreground">
                            <Clock
                                aria-hidden
                                className="mt-0.5 size-3.5 shrink-0"
                            />
                            {selectedMethod?.collected_on_delivery
                                ? 'Pesanan COD langsung diproses cabang. Siapkan uang tunai sesuai total saat kurir tiba.'
                                : `Selesaikan pembayaran dalam ${formatDuration(reservationMinutes)} setelah pesanan dibuat, selama stok masih ditahan untuk Anda.`}
                        </p>
                    </aside>
                </div>
            </form>
        </>
    );
}
