import { Head, Link, setLayoutProps } from '@inertiajs/react';
import PromotionController from '@/actions/App/Http/Controllers/Admin/PromotionController';
import { PromotionForm } from '@/components/admin/promotion-form';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { edit, index as promotionsIndex } from '@/routes/admin/promotions';
import type { PromotionDetail, PromotionFormOptions } from '@/types';

type PromotionsEditProps = {
    promotion: PromotionDetail;
    options: PromotionFormOptions;
};

export default function PromotionsEdit({
    promotion,
    options,
}: PromotionsEditProps) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Promo Produk', href: promotionsIndex() },
            { title: promotion.name, href: edit(promotion.id) },
        ],
    });

    return (
        <>
            <Head title={`Promo ${promotion.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title={promotion.name}
                    description="Perubahan langsung memengaruhi harga produk yang dipilih."
                />

                <Card className="max-w-5xl">
                    <CardHeader>
                        <CardTitle>Data promo</CardTitle>
                        <CardDescription>
                            Promo yang sudah berjalan tidak dihapus; nonaktifkan
                            bila tidak dipakai lagi, agar riwayat harga pesanan
                            tetap bisa dijelaskan.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <PromotionForm
                            action={PromotionController.update.form(
                                promotion.id,
                            )}
                            options={options}
                            promotion={promotion}
                            submitLabel="Simpan Perubahan"
                            secondaryAction={
                                <Button variant="ghost" asChild>
                                    <Link href={PromotionController.index()}>
                                        Kembali ke daftar
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
