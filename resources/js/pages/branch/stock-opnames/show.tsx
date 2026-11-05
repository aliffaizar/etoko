import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { ArrowLeft, Check, X } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { StatCard } from '@/components/panel/stat-card';
import { StatusBadge, type StatusTone } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateShort, formatDateTime, formatNumber } from '@/lib/format';
import stockOpnames from '@/routes/branch/stock-opnames';
import type {
    StockOpnameDocument,
    StockOpnameLine,
    StockWorkspaceProps,
} from '@/types';

type StockOpnameShowProps = StockWorkspaceProps & {
    opname: StockOpnameDocument;
    can: { record_counts: boolean; complete: boolean; cancel: boolean };
};

const STATUS_TONE: Record<string, StatusTone> = {
    draft: 'neutral',
    in_progress: 'warning',
    completed: 'success',
    cancelled: 'danger',
};

/**
 * The counting sheet (P1-E4-T7).
 *
 * Three columns that must not be confused: **sistem** is what the system
 * believed when the session opened, **hasil hitung** is what was found on the
 * shelf, and **selisih** is the difference — which is what gets posted when
 * the session closes, not the counted figure. That is why a sale during the
 * count is not undone by the count.
 *
 * An empty count box means "not counted yet", which is different from a
 * counted zero; both are kept, and only the second posts an adjustment.
 *
 * Saving and closing are separate buttons with separate permissions, so the
 * person walking the shelves is not also the person approving what they found.
 *
 * There is no warehouse selector here, unlike the listings: a session belongs
 * to the warehouse it was opened for, and switching would mean looking at a
 * different session's sheet.
 */
export default function StockOpnameShow({
    branch,
    warehouse,
    opname,
    can,
}: StockOpnameShowProps) {
    const [counts, setCounts] = useState<Record<number, string>>(() =>
        Object.fromEntries(
            opname.items.map((item) => [
                item.id,
                item.counted_quantity === null
                    ? ''
                    : String(item.counted_quantity),
            ]),
        ),
    );
    const [notes, setNotes] = useState<Record<number, string>>(() =>
        Object.fromEntries(
            opname.items.map((item) => [item.id, item.notes ?? '']),
        ),
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [isSaving, setIsSaving] = useState(false);

    const isEditable = opname.is_editable && can.record_counts;

    setLayoutProps({
        breadcrumbs: [
            { title: 'Stok Opname', href: stockOpnames.index() },
            { title: opname.code, href: stockOpnames.show(opname.id) },
        ],
    });

    const countedLines = opname.items.filter(
        (item) => counts[item.id] !== undefined && counts[item.id] !== '',
    );

    const differenceFor = (item: StockOpnameLine): number | null => {
        const typed = counts[item.id];

        if (typed === undefined || typed === '') {
            return null;
        }

        return Number(typed) - item.system_quantity;
    };

    const save = (): void => {
        router.put(
            stockOpnames.counts.update(opname.id).url,
            {
                items: opname.items.map((item) => ({
                    id: item.id,
                    counted_quantity:
                        counts[item.id] === '' ? null : Number(counts[item.id]),
                    notes: notes[item.id] || null,
                })),
            },
            {
                preserveScroll: true,
                onStart: () => setIsSaving(true),
                onFinish: () => setIsSaving(false),
                onError: (received) => setErrors(received),
                onSuccess: () => setErrors({}),
            },
        );
    };

    return (
        <>
            <Head title={`${opname.code} · ${warehouse.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={`Opname ${opname.code}`}
                        description={`Gudang ${warehouse.name} · cabang ${branch.name} · dihitung ${formatDateShort(opname.counted_at)}. Yang diposting saat sesi ditutup adalah selisihnya, bukan hasil hitungnya.`}
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        <Button variant="outline" asChild>
                            <Link href={stockOpnames.index()}>
                                <ArrowLeft aria-hidden />
                                Kembali
                            </Link>
                        </Button>
                        <StatusBadge
                            label={opname.status_label}
                            tone={STATUS_TONE[opname.status] ?? 'neutral'}
                        />
                    </div>
                </div>

                {errors.status && (
                    <p className="rounded-lg border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive">
                        {errors.status}
                    </p>
                )}

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label="Baris dihitung"
                        value={countedLines.length}
                        hint={`dari ${formatNumber(opname.items.length)} baris`}
                    />
                    <StatCard
                        label="Baris selisih"
                        value={
                            opname.items.filter((item) => {
                                const difference = differenceFor(item);

                                return difference !== null && difference !== 0;
                            }).length
                        }
                    />
                    <StatCard
                        label="Dibuat oleh"
                        value={opname.created_by ?? '—'}
                    />
                    <StatCard
                        label="Ditutup"
                        value={
                            opname.completed_at === null
                                ? 'Belum'
                                : formatDateTime(opname.completed_at)
                        }
                        hint={
                            opname.approved_by
                                ? `oleh ${opname.approved_by}`
                                : undefined
                        }
                    />
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Varian</TableHead>
                                <TableHead className="text-right">
                                    Sistem
                                </TableHead>
                                <TableHead className="text-right">
                                    Hasil hitung
                                </TableHead>
                                <TableHead className="text-right">
                                    Selisih
                                </TableHead>
                                <TableHead>Catatan</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {opname.items.map((item, index) => {
                                const difference = differenceFor(item);

                                return (
                                    <TableRow key={item.id}>
                                        <TableCell>
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium">
                                                    {item.product_name}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    <span className="font-mono">
                                                        {item.sku}
                                                    </span>
                                                </p>
                                                {errors[
                                                    `items.${index}.counted_quantity`
                                                ] && (
                                                    <p className="text-xs text-destructive">
                                                        {
                                                            errors[
                                                                `items.${index}.counted_quantity`
                                                            ]
                                                        }
                                                    </p>
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right text-sm text-muted-foreground tabular-nums">
                                            {formatNumber(item.system_quantity)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {isEditable ? (
                                                <Input
                                                    type="number"
                                                    min={0}
                                                    className="ml-auto w-24 text-right"
                                                    aria-label={`Hasil hitung ${item.sku}`}
                                                    placeholder="—"
                                                    value={
                                                        counts[item.id] ?? ''
                                                    }
                                                    onChange={(event) =>
                                                        setCounts({
                                                            ...counts,
                                                            [item.id]:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                />
                                            ) : (
                                                <span className="text-sm tabular-nums">
                                                    {item.counted_quantity ===
                                                    null
                                                        ? 'belum dihitung'
                                                        : formatNumber(
                                                              item.counted_quantity,
                                                          )}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {difference === null ? (
                                                <span className="text-xs text-muted-foreground">
                                                    —
                                                </span>
                                            ) : (
                                                <span
                                                    className={
                                                        difference === 0
                                                            ? 'text-sm text-muted-foreground tabular-nums'
                                                            : difference < 0
                                                              ? 'text-sm font-medium text-destructive tabular-nums'
                                                              : 'text-sm font-medium text-emerald-600 tabular-nums dark:text-emerald-400'
                                                    }
                                                >
                                                    {difference > 0 ? '+' : ''}
                                                    {formatNumber(difference)}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {isEditable ? (
                                                <Input
                                                    className="w-48"
                                                    maxLength={255}
                                                    aria-label={`Catatan ${item.sku}`}
                                                    value={notes[item.id] ?? ''}
                                                    onChange={(event) =>
                                                        setNotes({
                                                            ...notes,
                                                            [item.id]:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                />
                                            ) : (
                                                <span className="text-xs text-muted-foreground">
                                                    {item.notes ?? '—'}
                                                </span>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>

                <div className="flex flex-wrap items-center justify-end gap-2">
                    {can.cancel && opname.is_editable && (
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    stockOpnames.cancel(opname.id).url,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <X aria-hidden />
                            Batalkan Sesi
                        </Button>
                    )}
                    {isEditable && (
                        <Button
                            variant="secondary"
                            disabled={isSaving}
                            onClick={save}
                        >
                            Simpan Hasil Hitung
                        </Button>
                    )}
                    {can.complete && opname.is_editable && (
                        <Button
                            disabled={opname.completion_blocked_reason !== null}
                            title={
                                opname.completion_blocked_reason ?? undefined
                            }
                            onClick={() =>
                                router.post(
                                    stockOpnames.complete(opname.id).url,
                                    {},
                                    {
                                        preserveScroll: true,
                                        onError: (received) =>
                                            setErrors(received),
                                    },
                                )
                            }
                        >
                            <Check aria-hidden />
                            Selesaikan & Catat Selisih
                        </Button>
                    )}
                </div>

                {opname.completion_blocked_reason && can.complete && (
                    <p className="text-right text-xs text-muted-foreground">
                        {opname.completion_blocked_reason}
                    </p>
                )}

                {isEditable && (
                    <p className="text-right text-xs text-muted-foreground">
                        Simpan dulu bila belum selesai menghitung — sesi bisa
                        dilanjutkan kapan saja. Baris yang dibiarkan kosong
                        tidak akan mengubah stok.
                    </p>
                )}
            </div>
        </>
    );
}
