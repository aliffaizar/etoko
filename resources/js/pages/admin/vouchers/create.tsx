import { Head, Link } from '@inertiajs/react';
import VoucherController from '@/actions/App/Http/Controllers/Admin/VoucherController';
import { VoucherForm } from '@/components/admin/voucher-form';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { create, index as vouchersIndex } from '@/routes/admin/vouchers';
import type { VoucherFormOptions } from '@/types';

type VouchersCreateProps = {
    options: VoucherFormOptions;
};

export default function VouchersCreate({ options }: VouchersCreateProps) {
    return (
        <>
            <Head title="Tambah Voucher" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Tambah Voucher"
                    description="Voucher dipakai pelanggan di keranjang dan dicek ulang saat pesanan dibuat."
                />

                <Card className="max-w-5xl">
                    <CardContent>
                        <VoucherForm
                            action={VoucherController.store.form()}
                            options={options}
                            submitLabel="Simpan Voucher"
                            secondaryAction={
                                <Button variant="ghost" asChild>
                                    <Link href={VoucherController.index()}>
                                        Batal
                                    </Link>
                                </Button>
                            }
                        />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

VouchersCreate.layout = {
    breadcrumbs: [
        { title: 'Voucher', href: vouchersIndex() },
        { title: 'Tambah', href: create() },
    ],
};
