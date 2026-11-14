import { Head, Link } from '@inertiajs/react';
import BannerController from '@/actions/App/Http/Controllers/Admin/BannerController';
import { BannerForm } from '@/components/admin/banner-form';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { create, index as bannersIndex } from '@/routes/admin/banners';
import type { BannerFormOptions } from '@/types';

type BannersCreateProps = {
    options: BannerFormOptions;
};

export default function BannersCreate({ options }: BannersCreateProps) {
    return (
        <>
            <Head title="Tambah Banner" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Tambah Banner"
                    description="Gambar utama harus diunggah. Banner baru langsung tampil jika aktif dan periodenya berlaku."
                />

                <Card className="max-w-5xl">
                    <CardContent>
                        <BannerForm
                            action={BannerController.store.form()}
                            options={options}
                            submitLabel="Simpan Banner"
                            secondaryAction={
                                <Button variant="ghost" asChild>
                                    <Link href={BannerController.index()}>
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

BannersCreate.layout = {
    breadcrumbs: [
        { title: 'Banner', href: bannersIndex() },
        { title: 'Tambah', href: create() },
    ],
};
