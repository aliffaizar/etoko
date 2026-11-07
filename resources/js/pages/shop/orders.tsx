import { Head, Link, router } from '@inertiajs/react';
import { Package, ShoppingBag } from 'lucide-react';
import { StatusBadge } from '@/components/panel/status-badge';
import { ShopEmpty } from '@/components/shop/shop-empty';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDateTime, formatRupiah } from '@/lib/format';
import { index as catalogIndex } from '@/routes/shop/catalog';
import account from '@/routes/shop/account';
import { ORDER_STATUS_TONE } from '@/types/order';
import type { PaginatedOrders } from '@/types/order';

type OrdersProps = {
    orders: PaginatedOrders;
    filters: { status: string };
    statuses: { value: string; label: string }[];
};

const ALL = 'all';

export default function Orders({ orders, filters, statuses }: OrdersProps) {
    const changeStatus = (value: string) => {
        router.get(
            account.orders.index().url,
            value === ALL ? {} : { status: value },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Pesanan Saya" />

            <div className="mx-auto flex max-w-4xl flex-col gap-6 px-4 py-8">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="flex items-center gap-2 text-2xl font-semibold">
                            <Package aria-hidden className="size-6" />
                            Pesanan Saya
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {orders.total} pesanan tercatat.
                        </p>
                    </div>

                    <Select
                        value={filters.status === '' ? ALL : filters.status}
                        onValueChange={changeStatus}
                    >
                        <SelectTrigger
                            className="w-56"
                            aria-label="Saring menurut status"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>Semua status</SelectItem>
                            {statuses.map((status) => (
                                <SelectItem
                                    key={status.value}
                                    value={status.value}
                                >
                                    {status.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {orders.data.length === 0 ? (
                    <ShopEmpty
                        icon={ShoppingBag}
                        title={
                            filters.status === ''
                                ? 'Belum ada pesanan'
                                : 'Tidak ada pesanan berstatus itu'
                        }
                        description={
                            filters.status === ''
                                ? 'Pesanan Anda akan muncul di sini setelah checkout.'
                                : 'Coba pilih status lain untuk melihat pesanan Anda yang lain.'
                        }
                        action={
                            filters.status === '' ? (
                                <Button asChild>
                                    <Link href={catalogIndex().url}>
                                        Mulai belanja
                                    </Link>
                                </Button>
                            ) : (
                                <Button
                                    variant="outline"
                                    onClick={() => changeStatus(ALL)}
                                >
                                    Lihat semua pesanan
                                </Button>
                            )
                        }
                    />
                ) : (
                    <ul className="flex flex-col gap-3">
                        {orders.data.map((order) => (
                            <li key={order.id}>
                                <Link
                                    href={account.orders.show(order.id).url}
                                    className="flex flex-wrap items-center justify-between gap-4 rounded-lg border p-4 transition-colors hover:bg-accent"
                                >
                                    <div className="min-w-0">
                                        <p className="font-medium tabular-nums">
                                            {order.order_number}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {formatDateTime(order.created_at)}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {order.item_count} item ·{' '}
                                            {order.fulfillment_label}
                                            {order.branch
                                                ? ` · ${order.branch.name}`
                                                : ''}
                                        </p>
                                    </div>

                                    <div className="flex flex-col items-end gap-2">
                                        <StatusBadge
                                            label={order.status_label}
                                            tone={
                                                ORDER_STATUS_TONE[order.status]
                                            }
                                        />
                                        <span className="font-semibold tabular-nums">
                                            {formatRupiah(order.grand_total)}
                                        </span>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                {orders.last_page > 1 && (
                    <nav
                        aria-label="Halaman pesanan"
                        className="flex items-center justify-between gap-4"
                    >
                        <Button
                            variant="outline"
                            disabled={orders.current_page <= 1}
                            onClick={() =>
                                router.get(account.orders.index().url, {
                                    ...(filters.status === ''
                                        ? {}
                                        : { status: filters.status }),
                                    page: orders.current_page - 1,
                                })
                            }
                        >
                            Sebelumnya
                        </Button>
                        <span className="text-sm text-muted-foreground">
                            Halaman {orders.current_page} dari{' '}
                            {orders.last_page}
                        </span>
                        <Button
                            variant="outline"
                            disabled={orders.current_page >= orders.last_page}
                            onClick={() =>
                                router.get(account.orders.index().url, {
                                    ...(filters.status === ''
                                        ? {}
                                        : { status: filters.status }),
                                    page: orders.current_page + 1,
                                })
                            }
                        >
                            Berikutnya
                        </Button>
                    </nav>
                )}
            </div>
        </>
    );
}
