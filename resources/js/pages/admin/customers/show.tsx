import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, MapPin, UserRound } from 'lucide-react';
import AdminLayout from '@/layouts/admin-layout';
import Heading from '@/components/heading';
import { StatusBadge } from '@/components/panel/status-badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateShort, formatRelative } from '@/lib/format';
import { index as customersIndex } from '@/routes/admin/customers';

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
};

export default function CustomerShow({ customer }: CustomerShowProps) {
    return (
        <AdminLayout
            breadcrumbs={[
                { title: 'Dashboard', href: '/admin' },
                { title: 'Pelanggan', href: customersIndex().url },
                { title: customer.name, href: '#' },
            ]}
        >
            <Head title={`Pelanggan — ${customer.name}`} />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex items-center gap-3">
                    <Link
                        href={customersIndex().url}
                        className="shrink-0 text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-5" />
                    </Link>
                    <Heading title={customer.name} description="Detail profil pelanggan." />
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    {/* Profile card */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <UserRound className="size-4" />
                                Profil
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                            <span className="text-muted-foreground">Email</span>
                            <span>{customer.email}</span>

                            <span className="text-muted-foreground">Telepon</span>
                            <span>{customer.phone ?? '–'}</span>

                            <span className="text-muted-foreground">Status</span>
                            <StatusBadge
                                variant={customer.is_active ? 'success' : 'danger'}
                                label={customer.is_active ? 'Aktif' : 'Nonaktif'}
                            />

                            <span className="text-muted-foreground">Email terverifikasi</span>
                            <span>
                                {customer.email_verified_at
                                    ? formatDateShort(customer.email_verified_at)
                                    : <span className="text-warning-foreground">Belum</span>}
                            </span>

                            <span className="text-muted-foreground">Login terakhir</span>
                            <span>
                                {customer.last_login_at ? formatRelative(customer.last_login_at) : 'Belum pernah'}
                            </span>

                            <span className="text-muted-foreground">Bergabung</span>
                            <span>{customer.created_at ? formatDateShort(customer.created_at) : '–'}</span>
                        </CardContent>
                    </Card>

                    {/* Activity summary */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Ringkasan Aktivitas</CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-2 gap-4">
                            <div className="rounded-lg border p-3 text-center">
                                <div className="text-2xl font-semibold">{customer.order_count}</div>
                                <div className="text-xs text-muted-foreground">Total Pesanan</div>
                            </div>
                            <div className="rounded-lg border p-3 text-center">
                                <div className="text-2xl font-semibold">
                                    {customer.total_spend > 0
                                        ? new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(customer.total_spend)
                                        : '–'}
                                </div>
                                <div className="text-xs text-muted-foreground">Total Belanja</div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Addresses */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <MapPin className="size-4" />
                            Alamat Tersimpan ({customer.addresses.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {customer.addresses.length === 0 ? (
                            <p className="text-sm text-muted-foreground">Belum ada alamat tersimpan.</p>
                        ) : (
                            <div className="grid gap-2 sm:grid-cols-2">
                                {customer.addresses.map((address) => (
                                    <div
                                        key={address.id}
                                        className="flex items-start gap-3 rounded-lg border p-3 text-sm"
                                    >
                                        <MapPin className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium">{address.label}</span>
                                                {address.is_default && (
                                                    <span className="rounded-full bg-primary/10 px-1.5 py-0.5 text-xs font-medium text-primary">
                                                        Utama
                                                    </span>
                                                )}
                                            </div>
                                            <div className="truncate text-muted-foreground">
                                                {address.recipient_name} · {address.city_name}, {address.province_name}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Orders placeholder */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Riwayat Pesanan</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="text-sm text-muted-foreground">
                            Riwayat pesanan akan tersedia setelah P1-E7 (Checkout & Order Management) selesai.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
