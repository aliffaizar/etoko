import { Head, Link } from '@inertiajs/react';
import PromotionController from '@/actions/App/Http/Controllers/Admin/PromotionController';
import { PromotionForm } from '@/components/admin/promotion-form';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { create, index as promotionsIndex } from '@/routes/admin/promotions';
import type { PromotionFormOptions } from '@/types';

type PromotionsCreateProps = {
    options: PromotionFormOptions;
};

export default function PromotionsCreate({ options }: PromotionsCreateProps) {
    return (
        <>
            <Head title="Tambah Promo" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Tambah Promo"
                    description="Promo mengubah harga produk yang dipilih selama periodenya berlaku."
                />

                <Card className="max-w-5xl">
                    <CardContent>
                        <PromotionForm
                            action={PromotionController.store.form()}
                            options={options}
                            submitLabel="Simpan Promo"
                            secondaryAction={
                                <Button variant="ghost" asChild>
                                    <Link href={PromotionController.index()}>
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

PromotionsCreate.layout = {
    breadcrumbs: [
        { title: 'Promo Produk', href: promotionsIndex() },
        { title: 'Tambah', href: create() },
    ],
};
