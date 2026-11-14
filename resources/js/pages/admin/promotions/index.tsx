import { Head, Link, router } from '@inertiajs/react';
import { Pencil, Plus, ToggleLeft, ToggleRight } from 'lucide-react';
import PromotionController from '@/actions/App/Http/Controllers/Admin/PromotionController';
import Heading from '@/components/heading';
import { SimplePagination } from '@/components/panel/simple-pagination';
import { StatusBadge } from '@/components/panel/status-badge';
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
    create as promotionsCreate,
    index as promotionsIndex,
} from '@/routes/admin/promotions';
import type { PaginatedPromotions } from '@/types';

type PromotionsIndexProps = {
    promotions: PaginatedPromotions;
    can: { create: boolean };
};

export default function PromotionsIndex({
    promotions,
    can,
}: PromotionsIndexProps) {
    return (
        <>
            <Head title="Promo Produk" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Promo Produk"
                        description="Potongan harga untuk produk tertentu. Promo yang berlaku lebih tinggi dari harga cabang dan harga katalog."
                    />
                    {can.create && (
                        <Button asChild>
                            <Link href={promotionsCreate()}>
                                <Plus aria-hidden />
                                Tambah Promo
                            </Link>
                        </Button>
                    )}
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Promo</TableHead>
                                <TableHead>Potongan</TableHead>
                                <TableHead className="text-right">
                                    Prioritas
                                </TableHead>
                                <TableHead className="text-right">
                                    Produk
                                </TableHead>
                                <TableHead>Cabang</TableHead>
                                <TableHead>Periode</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">
                                    Aksi
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {promotions.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={8}
                                        className="py-8 text-center text-muted-foreground"
                                    >
                                        Belum ada promo produk.
                                    </TableCell>
                                </TableRow>
                            )}
                            {promotions.data.map((promotion) => (
                                <TableRow key={promotion.id}>
                                    <TableCell className="font-medium">
                                        {promotion.name}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {promotion.value_label}
                                        <span className="ml-1 text-xs text-muted-foreground">
                                            {promotion.type_label}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {promotion.priority}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {promotion.products_count}
                                    </TableCell>
                                    <TableCell className="text-xs">
                                        {promotion.branches_label}
                                    </TableCell>
                                    <TableCell className="text-xs text-muted-foreground">
                                        {promotion.period_label}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            label={promotion.state_label}
                                            tone={promotion.state_tone}
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex justify-end gap-1">
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={
                                                    promotion.is_active
                                                        ? 'Nonaktifkan promo'
                                                        : 'Aktifkan promo'
                                                }
                                                onClick={() =>
                                                    router.patch(
                                                        PromotionController.updateStatus(
                                                            promotion.id,
                                                        ).url,
                                                        {
                                                            is_active:
                                                                !promotion.is_active,
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {promotion.is_active ? (
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
                                                    href={PromotionController.edit(
                                                        promotion.id,
                                                    )}
                                                    aria-label={`Ubah ${promotion.name}`}
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

                <SimplePagination links={promotions.links} />
            </div>
        </>
    );
}

PromotionsIndex.layout = {
    breadcrumbs: [{ title: 'Promo Produk', href: promotionsIndex() }],
};
