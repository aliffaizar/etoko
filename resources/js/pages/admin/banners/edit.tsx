import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import BannerController from '@/actions/App/Http/Controllers/Admin/BannerController';
import { BannerForm } from '@/components/admin/banner-form';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { edit, index as bannersIndex } from '@/routes/admin/banners';
import type { BannerDetail, BannerFormOptions } from '@/types';

type BannersEditProps = {
    banner: BannerDetail;
    options: BannerFormOptions;
};

export default function BannersEdit({ banner, options }: BannersEditProps) {
    const [confirmOpen, setConfirmOpen] = useState(false);

    setLayoutProps({
        breadcrumbs: [
            { title: 'Banner', href: bannersIndex() },
            { title: banner.title, href: edit(banner.id) },
        ],
    });

    return (
        <>
            <Head title={`Banner ${banner.title}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title={banner.title}
                    description="Ubah isi, periode, dan gambar banner."
                />

                <Card className="max-w-5xl">
                    <CardHeader>
                        <CardTitle>Data banner</CardTitle>
                        <CardDescription>
                            Perubahan langsung tampil di beranda sesuai periode
                            dan cabang.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <BannerForm
                            action={BannerController.update.form(banner.id)}
                            options={options}
                            banner={banner}
                            submitLabel="Simpan Perubahan"
                            secondaryAction={
                                <Button variant="ghost" asChild>
                                    <Link href={BannerController.index()}>
                                        Kembali ke daftar
                                    </Link>
                                </Button>
                            }
                        />
                    </CardContent>
                </Card>

                <Dialog open={confirmOpen} onOpenChange={setConfirmOpen}>
                    <DialogTrigger asChild>
                        <Button variant="destructive" className="self-start">
                            Hapus banner
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Hapus banner?</DialogTitle>
                            <DialogDescription>
                                Gambar banner ikut dihapus. Tindakan ini tidak
                                bisa dibatalkan.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button variant="ghost">Batal</Button>
                            </DialogClose>
                            <Button
                                variant="destructive"
                                onClick={() => {
                                    router.delete(
                                        BannerController.destroy(banner.id).url,
                                    );
                                }}
                            >
                                Hapus banner
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </>
    );
}
