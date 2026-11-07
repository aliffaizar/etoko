import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, MapPin, Package, UserRound } from 'lucide-react';
import Heading from '@/components/heading';
import { StatusBadge } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateShort, formatRelative, formatRupiah } from '@/lib/format';
import { show as orderShow } from '@/routes/admin/orders';
import { index as customersIndex } from '@/routes/admin/customers';
import { ORDER_STATUS_TONE } from '@/types/order';
import type { OrderRow } from '@/types/order';

type AddressItem = {
    id: number;
    label: string;
    recipient_name: string;
    city_name: string;
    province_name: string;
    is_default: boolean;
};

type CustomerDetail = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    is_active: boolean;
    email_verified_at: string | null;
    last_login_at: string | null;
    created_at: string | null;
    addresses: AddressItem[];
    order_count: number;
    total_spend: number;
};

type CustomerShowProps = {
    customer: CustomerDetail;
    orders: OrderRow[];
};

export default function CustomerShow({ customer, orders }: CustomerShowProps) {
    return (
        <>
            <Head title={`Pelanggan — ${customer.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center gap-3">
                    <Button variant="ghost" size="icon" asChild>
                        <Link
                            href={customersIndex().url}
                            aria-label="Kembali ke daftar pelanggan"
                        >
                            <ArrowLeft aria-hidden />
                        </Link>
                    </Button>
                    <Heading
                        title={customer.name}
                        description="Profil pelanggan, alamat, dan riwayat pesanannya."
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <UserRound aria-hidden className="size-4" />
                                Profil
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-[9rem_1fr] gap-x-4 gap-y-3 text-sm">
                                <dt className="text-muted-foreground">Email</dt>
                                <dd className="truncate">{customer.email}</dd>

                                <dt className="text-muted-foreground">
                                    Telepon
                                </dt>
                                <dd>{customer.phone ?? '-'}</dd>

                                <dt className="text-muted-foreground">
                                    Status
                                </dt>
                                <dd>
                                    <StatusBadge
                                        label={
                                            customer.is_active
                                                ? 'Aktif'
                                                : 'Nonaktif'
                                        }
                                        tone={
                                            customer.is_active
                                                ? 'success'
                                                : 'danger'
                                        }
                                    />
                                </dd>

                                <dt className="text-muted-foreground">
                                    Email terverifikasi
                                </dt>
                                <dd>
                                    {customer.email_verified_at ? (
                                        formatDateShort(
                                            customer.email_verified_at,
                                        )
                                    ) : (
                                        <span className="text-muted-foreground">
                                            Belum
                                        </span>
                                    )}
                                </dd>

                                <dt className="text-muted-foreground">
                                    Login terakhir
                                </dt>
                                <dd>
                                    {customer.last_login_at
                                        ? formatRelative(customer.last_login_at)
                                        : 'Belum pernah'}
                                </dd>

                                <dt className="text-muted-foreground">
                                    Bergabung
                                </dt>
                                <dd>{formatDateShort(customer.created_at)}</dd>
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Ringkasan Belanja
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-2 gap-4">
                            <div className="rounded-lg border p-4">
                                <p className="text-2xl font-semibold tabular-nums">
                                    {customer.order_count}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Pesanan
                                </p>
                            </div>
                            <div className="rounded-lg border p-4">
                                <p className="text-2xl font-semibold tabular-nums">
                                    {formatRupiah(customer.total_spend)}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Total belanja (lunas)
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <MapPin aria-hidden className="size-4" />
                            Alamat Tersimpan ({customer.addresses.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {customer.addresses.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Belum ada alamat tersimpan.
                            </p>
                        ) : (
                            <ul className="grid gap-2 sm:grid-cols-2">
                                {customer.addresses.map((address) => (
                                    <li
                                        key={address.id}
                                        className="flex items-start gap-3 rounded-lg border p-3 text-sm"
                                    >
                                        <MapPin
                                            aria-hidden
                                            className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                        />
                                        <div className="min-w-0 flex-1">
                                            <p className="flex items-center gap-2 font-medium">
                                                {address.label}
                                                {address.is_default && (
                                                    <StatusBadge
                                                        label="Utama"
                                                        tone="info"
                                                    />
                                                )}
                                            </p>
                                            <p className="truncate text-muted-foreground">
                                                {address.recipient_name} ·{' '}
                                                {address.city_name},{' '}
                                                {address.province_name}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Package aria-hidden className="size-4" />
                            Pesanan Terakhir
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {orders.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Pelanggan ini belum pernah memesan.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {orders.map((order) => (
                                    <li
                                        key={order.id}
                                        className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                                    >
                                        <div className="min-w-0">
                                            <Link
                                                href={orderShow(order.id).url}
                                                className="font-medium tabular-nums hover:underline"
                                            >
                                                {order.order_number}
                                            </Link>
                                            <p className="text-xs text-muted-foreground">
                                                {formatDateShort(
                                                    order.created_at,
                                                )}
                                                {order.branch
                                                    ? ` · ${order.branch.name}`
                                                    : ''}
                                                {` · ${order.item_count} item`}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <StatusBadge
                                                label={order.status_label}
                                                tone={
                                                    ORDER_STATUS_TONE[
                                                        order.status
                                                    ]
                                                }
                                            />
                                            <span className="text-sm font-medium tabular-nums">
                                                {formatRupiah(
                                                    order.grand_total,
                                                )}
                                            </span>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
