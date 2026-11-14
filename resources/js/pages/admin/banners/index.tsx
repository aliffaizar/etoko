import { Head, Link, router } from '@inertiajs/react';
import { Pencil, Plus, ToggleLeft, ToggleRight } from 'lucide-react';
import BannerController from '@/actions/App/Http/Controllers/Admin/BannerController';
import Heading from '@/components/heading';
import { StatusBadge } from '@/components/panel/status-badge';
import { SimplePagination } from '@/components/panel/simple-pagination';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    create as bannersCreate,
    index as bannersIndex,
} from '@/routes/admin/banners';
import type { PaginatedBanners } from '@/types';

type BannersIndexProps = {
    banners: PaginatedBanners;
    can: { create: boolean };
};

export default function BannersIndex({ banners, can }: BannersIndexProps) {
    return (
        <>
            <Head title="Banner" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Banner"
                        description="Gambar promosi di beranda. Banner tampil saat aktif dan berada dalam periodenya."
                    />
                    {can.create && (
                        <Button asChild>
                            <Link href={bannersCreate()}>
                                <Plus aria-hidden />
                                Tambah Banner
                            </Link>
                        </Button>
                    )}
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Banner</TableHead>
                                <TableHead>Posisi</TableHead>
                                <TableHead>Cabang</TableHead>
                                <TableHead>Periode</TableHead>
                                <TableHead className="text-right">
                                    Urutan
                                </TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">
                                    Aksi
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {banners.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={7}
                                        className="py-8 text-center text-muted-foreground"
                                    >
                                        Belum ada banner.
                                    </TableCell>
                                </TableRow>
                            )}
                            {banners.data.map((banner) => (
                                <TableRow key={banner.id}>
                                    <TableCell>
                                        <div className="flex items-center gap-3">
                                            {banner.image_url ? (
                                                <img
                                                    src={banner.image_url}
                                                    alt=""
                                                    className="h-10 w-16 shrink-0 rounded border object-cover"
                                                />
                                            ) : (
                                                <div className="h-10 w-16 shrink-0 rounded border bg-muted" />
                                            )}
                                            <span className="font-medium">
                                                {banner.title}
                                            </span>
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        {banner.position_label}
                                    </TableCell>
                                    <TableCell>{banner.branch_label}</TableCell>
                                    <TableCell className="text-xs text-muted-foreground">
                                        {banner.period_label}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {banner.sort_order}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            label={banner.state_label}
                                            tone={banner.state_tone}
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex justify-end gap-1">
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={
                                                    banner.is_active
                                                        ? 'Sembunyikan banner'
                                                        : 'Tampilkan banner'
                                                }
                                                onClick={() =>
                                                    router.patch(
                                                        BannerController.updateStatus(
                                                            banner.id,
                                                        ).url,
                                                        {
                                                            is_active:
                                                                !banner.is_active,
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {banner.is_active ? (
                                                    <ToggleRight aria-hidden />
                                                ) : (
                                                    <ToggleLeft aria-hidden />
                                                )}
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                asChild
                                            >
                                                <Link
                                                    href={BannerController.edit(
                                                        banner.id,
                                                    )}
                                                    aria-label={`Ubah ${banner.title}`}
                                                >
                                                    <Pencil aria-hidden />
                                                </Link>
                                            </Button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <SimplePagination links={banners.links} />
            </div>
        </>
    );
}

BannersIndex.layout = {
    breadcrumbs: [{ title: 'Banner', href: bannersIndex() }],
};
