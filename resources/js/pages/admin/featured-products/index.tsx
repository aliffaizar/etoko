import { Head, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Plus, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import FeaturedProductController from '@/actions/App/Http/Controllers/Admin/FeaturedProductController';
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
import { Input } from '@/components/ui/input';
import { formatRupiah } from '@/lib/format';
import { index as featuredIndex } from '@/routes/admin/featured-products';
import type { FeaturedProductRow, MarketingPickOption } from '@/types';

type FeaturedProductsIndexProps = {
    featured: FeaturedProductRow[];
    candidates: MarketingPickOption[];
    /** How many of the list the front page's rail shows. */
    shown_on_home: number;
};

/**
 * Which products the front page features, and in what order (P2-E8-T4).
 *
 * The list is edited in place and saved once: its order is the decision, so
 * adding, removing and moving a product all end in the same save.
 */
export default function FeaturedProductsIndex({
    featured,
    candidates,
    shown_on_home: shownOnHome,
}: FeaturedProductsIndexProps) {
    const [rows, setRows] = useState<FeaturedProductRow[]>(featured);
    const [picked, setPicked] = useState<MarketingPickOption[]>(candidates);
    const [query, setQuery] = useState('');
    const [saving, setSaving] = useState(false);

    const dirty = useMemo(
        () =>
            rows.map((row) => row.id).join(',') !==
            featured.map((row) => row.id).join(','),
        [rows, featured],
    );

    const suggestions = useMemo(() => {
        const needle = query.trim().toLowerCase();

        return needle === ''
            ? []
            : picked
                  .filter((option) =>
                      option.label.toLowerCase().includes(needle),
                  )
                  .slice(0, 8);
    }, [picked, query]);

    const add = (option: MarketingPickOption) => {
        setRows((current) => [
            ...current,
            {
                id: option.value,
                name: option.label,
                slug: '',
                price: null,
                is_active: true,
                is_published: true,
            },
        ]);
        setPicked((current) =>
            current.filter((candidate) => candidate.value !== option.value),
        );
        setQuery('');
    };

    const remove = (row: FeaturedProductRow) => {
        setRows((current) => current.filter((item) => item.id !== row.id));
        setPicked((current) => [
            ...current,
            { value: row.id, label: row.name },
        ]);
    };

    const move = (index: number, direction: -1 | 1) => {
        setRows((current) => {
            const target = index + direction;

            if (target < 0 || target >= current.length) {
                return current;
            }

            const next = [...current];
            [next[index], next[target]] = [next[target], next[index]];

            return next;
        });
    };

    const save = () => {
        setSaving(true);
        router.put(
            FeaturedProductController.update.url(),
            { product_ids: rows.map((row) => row.id) },
            {
                preserveScroll: true,
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <>
            <Head title="Produk Unggulan" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Produk Unggulan"
                        description="Produk yang tampil di bagian unggulan beranda, sesuai urutan di sini."
                    />
                    <Button onClick={save} disabled={!dirty || saving}>
                        Simpan urutan
                    </Button>
                </div>

                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Daftar unggulan ({rows.length})</CardTitle>
                        <CardDescription>
                            Beranda menampilkan {shownOnHome} produk pertama.
                            Produk yang tidak aktif atau belum terbit tidak
                            tampil walau masuk daftar.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        <div className="grid gap-2">
                            <label
                                htmlFor="featured-search"
                                className="text-sm font-medium"
                            >
                                Tambah produk
                            </label>
                            <Input
                                id="featured-search"
                                type="search"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                placeholder="Cari nama produk…"
                            />
                            {suggestions.length > 0 && (
                                <ul className="divide-y rounded-md border">
                                    {suggestions.map((option) => (
                                        <li key={option.value}>
                                            <button
                                                type="button"
                                                onClick={() => add(option)}
                                                className="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-muted"
                                            >
                                                {option.label}
                                                <Plus
                                                    aria-hidden
                                                    className="size-4"
                                                />
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>

                        {rows.length === 0 ? (
                            <p className="rounded-md border p-6 text-center text-sm text-muted-foreground">
                                Belum ada produk unggulan. Cari produk di atas
                                untuk menambahkannya.
                            </p>
                        ) : (
                            <ol className="divide-y rounded-md border">
                                {rows.map((row, index) => (
                                    <li
                                        key={row.id}
                                        className="flex flex-wrap items-center gap-3 px-3 py-2"
                                    >
                                        <span className="w-6 text-sm text-muted-foreground tabular-nums">
                                            {index + 1}.
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">
                                                {row.name}
                                            </p>
                                            {row.price !== null && (
                                                <p className="text-xs text-muted-foreground">
                                                    mulai{' '}
                                                    {formatRupiah(row.price)}
                                                </p>
                                            )}
                                        </div>
                                        {!row.is_published && (
                                            <StatusBadge
                                                label="Belum terbit"
                                                tone="warning"
                                            />
                                        )}
                                        {!row.is_active && (
                                            <StatusBadge
                                                label="Nonaktif"
                                                tone="neutral"
                                            />
                                        )}
                                        {index >= shownOnHome && (
                                            <span className="text-xs text-muted-foreground">
                                                tidak tampil di beranda
                                            </span>
                                        )}
                                        <div className="flex gap-1">
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Naikkan ${row.name}`}
                                                disabled={index === 0}
                                                onClick={() => move(index, -1)}
                                            >
                                                <ArrowUp aria-hidden />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Turunkan ${row.name}`}
                                                disabled={
                                                    index === rows.length - 1
                                                }
                                                onClick={() => move(index, 1)}
                                            >
                                                <ArrowDown aria-hidden />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Hapus ${row.name} dari unggulan`}
                                                onClick={() => remove(row)}
                                            >
                                                <X aria-hidden />
                                            </Button>
                                        </div>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

FeaturedProductsIndex.layout = {
    breadcrumbs: [{ title: 'Produk Unggulan', href: featuredIndex() }],
};
