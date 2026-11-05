import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { ArrowLeft, Check, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { StockLineEditor } from '@/components/branch/stock-line-editor';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { StatusBadge, type StatusTone } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime, formatNumber } from '@/lib/format';
import stockEntries from '@/routes/branch/stock-entries';
import type {
    StockEntryDocument,
    StockLineDraft,
    StockVariantOption,
    StockWorkspaceProps,
} from '@/types';

type StockEntryFormProps = StockWorkspaceProps & {
    /** Null while drafting a new document. */
    entry: StockEntryDocument | null;
    variantOptions: StockVariantOption[];
    can: {
        update: boolean;
        post: boolean;
        cancel: boolean;
        delete?: boolean;
    };
};

const STATUS_TONE: Record<string, StatusTone> = {
    draft: 'neutral',
    posted: 'success',
    cancelled: 'danger',
};

/**
 * A goods-in document, while it is a draft and after it is posted
 * (P1-E4-T5).
 *
 * "Simpan draf" and "Posting" are two buttons on purpose: saving changes the
 * document, posting changes the warehouse. Once posted the form becomes a
 * read-only record of what was committed, because its ledger rows are what
 * explain the figures it produced — reversing it means a goods-out note, not
 * an edit.
 */
export default function StockEntryForm({
    branch,
    warehouse,
    entry,
    variantOptions,
    can,
}: StockEntryFormProps) {
    const [lines, setLines] = useState<StockLineDraft[]>(
        (entry?.items ?? []).map((item) => ({
            product_variant_id: item.product_variant_id,
            sku: item.sku,
            display_name: item.display_name,
            product_name: item.product_name,
            quantity: item.quantity,
            unit_cost: item.unit_cost,
            available: null,
        })),
    );
    const [supplierName, setSupplierName] = useState(
        entry?.supplier_name ?? '',
    );
    const [referenceNo, setReferenceNo] = useState(entry?.reference_no ?? '');
    const [entryDate, setEntryDate] = useState(
        entry?.entry_date ?? new Date().toISOString().slice(0, 10),
    );
    const [notes, setNotes] = useState(entry?.notes ?? '');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [isSaving, setIsSaving] = useState(false);

    const isEditable = entry === null ? true : entry.is_editable && can.update;

    setLayoutProps({
        breadcrumbs: [
            { title: 'Barang Masuk', href: stockEntries.index() },
            {
                title: entry?.code ?? 'Dokumen Baru',
                href: entry
                    ? stockEntries.edit(entry.id)
                    : stockEntries.create(),
            },
        ],
    });

    const save = (): void => {
        const payload = {
            warehouse_id: warehouse.id,
            supplier_name: supplierName || null,
            reference_no: referenceNo || null,
            entry_date: entryDate,
            notes: notes || null,
            items: lines.map((line) => ({
                product_variant_id: line.product_variant_id,
                quantity: line.quantity,
                unit_cost: line.unit_cost,
            })),
        };

        const options = {
            preserveScroll: true,
            onStart: () => setIsSaving(true),
            onFinish: () => setIsSaving(false),
            onError: (received: Record<string, string>) => setErrors(received),
            onSuccess: () => setErrors({}),
        };

        if (entry === null) {
            router.post(stockEntries.store().url, payload, options);

            return;
        }

        router.put(stockEntries.update(entry.id).url, payload, options);
    };

    return (
        <>
            <Head
                title={`${entry?.code ?? 'Barang Masuk Baru'} · ${warehouse.name}`}
            />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={entry?.code ?? 'Barang Masuk Baru'}
                        description={`Gudang ${warehouse.name} · cabang ${branch.name}. Stok bertambah saat dokumen diposting, bukan saat disimpan.`}
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        <Button variant="outline" asChild>
                            <Link href={stockEntries.index()}>
                                <ArrowLeft aria-hidden />
                                Kembali
                            </Link>
                        </Button>
                        {entry && (
                            <StatusBadge
                                label={entry.status_label}
                                tone={STATUS_TONE[entry.status] ?? 'neutral'}
                            />
                        )}
                    </div>
                </div>

                {errors.status && (
                    <p className="rounded-lg border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive">
                        {errors.status}
                    </p>
                )}

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="lg:col-span-1">
                        <CardHeader>
                            <CardTitle>Keterangan dokumen</CardTitle>
                            <CardDescription>
                                Nama supplier dan nomor referensi disalin dari
                                surat jalan, supaya dokumen ini bisa dicocokkan
                                nanti.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="supplier_name">
                                    Nama supplier{' '}
                                    <span className="text-muted-foreground">
                                        (opsional)
                                    </span>
                                </Label>
                                <Input
                                    id="supplier_name"
                                    value={supplierName}
                                    disabled={!isEditable}
                                    maxLength={255}
                                    onChange={(event) =>
                                        setSupplierName(event.target.value)
                                    }
                                />
                                <InputError message={errors.supplier_name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="reference_no">
                                    Nomor referensi{' '}
                                    <span className="text-muted-foreground">
                                        (opsional)
                                    </span>
                                </Label>
                                <Input
                                    id="reference_no"
                                    value={referenceNo}
                                    disabled={!isEditable}
                                    maxLength={255}
                                    placeholder="contoh: DO-10293"
                                    onChange={(event) =>
                                        setReferenceNo(event.target.value)
                                    }
                                />
                                <InputError message={errors.reference_no} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="entry_date">
                                    Tanggal masuk
                                </Label>
                                <Input
                                    id="entry_date"
                                    type="date"
                                    value={entryDate}
                                    disabled={!isEditable}
                                    onChange={(event) =>
                                        setEntryDate(event.target.value)
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    Boleh tanggal lampau bila surat jalannya
                                    baru diketik hari ini, tetapi tidak boleh
                                    tanggal yang belum tiba.
                                </p>
                                <InputError message={errors.entry_date} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="notes">
                                    Catatan{' '}
                                    <span className="text-muted-foreground">
                                        (opsional)
                                    </span>
                                </Label>
                                <Textarea
                                    id="notes"
                                    rows={3}
                                    value={notes}
                                    disabled={!isEditable}
                                    maxLength={1000}
                                    onChange={(event) =>
                                        setNotes(event.target.value)
                                    }
                                />
                                <InputError message={errors.notes} />
                            </div>

                            {entry && !entry.is_editable && (
                                <dl className="grid gap-1 border-t pt-4 text-xs text-muted-foreground">
                                    <div className="flex justify-between gap-2">
                                        <dt>Dibuat oleh</dt>
                                        <dd>{entry.created_by ?? '—'}</dd>
                                    </div>
                                    <div className="flex justify-between gap-2">
                                        <dt>Diposting oleh</dt>
                                        <dd>{entry.posted_by ?? '—'}</dd>
                                    </div>
                                    <div className="flex justify-between gap-2">
                                        <dt>Waktu posting</dt>
                                        <dd>
                                            {formatDateTime(entry.posted_at)}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-2">
                                        <dt>Total unit</dt>
                                        <dd className="tabular-nums">
                                            {formatNumber(entry.total_quantity)}
                                        </dd>
                                    </div>
                                </dl>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>Baris barang</CardTitle>
                            <CardDescription>
                                Jumlah selalu positif: arah masuk ditentukan
                                jenis dokumennya.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <StockLineEditor
                                lines={lines}
                                onChange={setLines}
                                variantOptions={variantOptions}
                                isEditable={isEditable}
                                errors={errors}
                            />
                        </CardContent>
                    </Card>
                </div>

                <div className="flex flex-wrap items-center justify-end gap-2">
                    {entry && can.delete && entry.is_editable && (
                        <Button
                            variant="ghost"
                            className="text-destructive hover:text-destructive"
                            onClick={() =>
                                router.delete(
                                    stockEntries.destroy(entry.id).url,
                                )
                            }
                        >
                            <Trash2 aria-hidden />
                            Hapus draf
                        </Button>
                    )}
                    {entry && can.cancel && entry.is_editable && (
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    stockEntries.cancel(entry.id).url,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <X aria-hidden />
                            Batalkan
                        </Button>
                    )}
                    {isEditable && (
                        <Button
                            variant="secondary"
                            disabled={isSaving}
                            onClick={save}
                        >
                            {entry === null
                                ? 'Simpan Draf'
                                : 'Simpan Perubahan'}
                        </Button>
                    )}
                    {entry && can.post && (
                        <Button
                            disabled={entry.posting_blocked_reason !== null}
                            title={entry.posting_blocked_reason ?? undefined}
                            onClick={() =>
                                router.post(
                                    stockEntries.post(entry.id).url,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Check aria-hidden />
                            Posting & Tambah Stok
                        </Button>
                    )}
                </div>

                {entry?.posting_blocked_reason && can.post && (
                    <p className="text-right text-xs text-muted-foreground">
                        {entry.posting_blocked_reason}
                    </p>
                )}
            </div>
        </>
    );
}
