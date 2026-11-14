import { Head, Link, setLayoutProps } from '@inertiajs/react';
import VoucherController from '@/actions/App/Http/Controllers/Admin/VoucherController';
import OrderController from '@/actions/App/Http/Controllers/Admin/OrderController';
import { VoucherForm } from '@/components/admin/voucher-form';
import Heading from '@/components/heading';
import { StatusBadge } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatDateTime, formatRupiah } from '@/lib/format';
import { edit, index as vouchersIndex } from '@/routes/admin/vouchers';
import type {
    VoucherDetail,
    VoucherFormOptions,
    VoucherUsageRow,
} from '@/types';

type VouchersEditProps = {
    voucher: VoucherDetail;
    usages: VoucherUsageRow[];
    options: VoucherFormOptions;
};

export default function VouchersEdit({
    voucher,
    usages,
    options,
}: VouchersEditProps) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Voucher', href: vouchersIndex() },
            { title: voucher.code, href: edit(voucher.id) },
        ],
    });

    const quota =
        voucher.quota === null
            ? `${voucher.used_count} terpakai · kuota tanpa batas`
            : `${voucher.used_count} dari ${voucher.quota} terpakai`;

    return (
        <>
            <Head title={`Voucher ${voucher.code}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={voucher.code}
                        description={`${voucher.name} · ${voucher.benefit} · ${quota}`}
                    />
                    <StatusBadge
                        label={voucher.state.label}
                        tone={voucher.state.tone}
                    />
                </div>

                <Card className="max-w-5xl">
                    <CardHeader>
                        <CardTitle>Data voucher</CardTitle>
                        <CardDescription>
                            Perubahan berlaku untuk pesanan berikutnya. Pesanan
                            yang sudah dibuat tetap memakai potongan saat itu.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <VoucherForm
                            action={VoucherController.update.form(voucher.id)}
                            options={options}
                            voucher={voucher}
                            submitLabel="Simpan Perubahan"
                            secondaryAction={
                                <Button variant="ghost" asChild>
                                    <Link href={VoucherController.index()}>
                                        Kembali ke daftar
                                    </Link>
                                </Button>
                            }
                        />
                    </CardContent>
                </Card>

                <Card className="max-w-5xl">
                    <CardHeader>
                        <CardTitle>Pemakaian terakhir</CardTitle>
                        <CardDescription>
                            Pemakaian dari pesanan yang dibatalkan atau
                            kedaluwarsa dikembalikan ke kuota.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {usages.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Voucher ini belum pernah dipakai.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {usages.map((usage) => (
                                    <li
                                        key={usage.id}
                                        className="flex flex-wrap items-center justify-between gap-2 py-3"
                                    >
                                        <div className="min-w-0">
                                            <Link
                                                href={OrderController.show(
                                                    usage.order_id,
                                                )}
                                                className="font-medium tabular-nums hover:underline"
                                            >
                                                {usage.order_number}
                                            </Link>
                                            <p className="truncate text-xs text-muted-foreground">
                                                {usage.customer_name} ·{' '}
                                                {formatDateTime(usage.used_at)}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            {usage.released_at && (
                                                <StatusBadge
                                                    label="Dikembalikan"
                                                    tone="neutral"
                                                />
                                            )}
                                            <span className="text-sm tabular-nums">
                                                −
                                                {formatRupiah(
                                                    usage.discount_amount,
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
