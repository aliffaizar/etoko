import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { ArrowLeft, Check, X } from 'lucide-react';
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
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime, formatNumber } from '@/lib/format';
import stockIssues from '@/routes/branch/stock-issues';
import type {
    StockIssueDocument,
    StockLineDraft,
    StockScopeOption,
    StockVariantOption,
    StockWorkspaceProps,
} from '@/types';

type StockIssueFormProps = StockWorkspaceProps & {
    /** Null while drafting a new document. */
    issue: StockIssueDocument | null;
    variantOptions: StockVariantOption[];
    options: { reasons: StockScopeOption[] };
    can: { update: boolean; post: boolean; cancel: boolean };
};

const STATUS_TONE: Record<string, StatusTone> = {
    draft: 'neutral',
    posted: 'success',
    cancelled: 'danger',
};

/** The reason that explains nothing by itself, so the notes become required. */
const REASON_NEEDING_NOTES = 'other';

/**
 * A goods-out document (P1-E4-T6).
 *
 * Two things set it apart from a goods-in note. It has to say **why** — and
 * "Lainnya" asks for the explanation rather than accepting the shrug. And
 * posting it can fail: the warehouse may not hold enough, and that answer only
 * exists at the moment of posting, so it comes back as an error on the lines
 * rather than being guessed while the form is open (BR-6). When it fails,
 * nothing is issued at all.
 */
export default function StockIssueForm({
    branch,
    warehouse,
    issue,
    variantOptions,
    options,
    can,
}: StockIssueFormProps) {
    const [lines, setLines] = useState<StockLineDraft[]>(
        (issue?.items ?? []).map((item) => ({
            product_variant_id: item.product_variant_id,
            sku: item.sku,
            display_name: item.display_name,
            product_name: item.product_name,
            quantity: item.quantity,
            unit_cost: item.unit_cost,
            available: null,
        })),
    );
    const [reason, setReason] = useState(issue?.reason ?? 'damage');
    const [referenceNo, setReferenceNo] = useState(issue?.reference_no ?? '');
    const [issueDate, setIssueDate] = useState(
        issue?.issue_date ?? new Date().toISOString().slice(0, 10),
    );
    const [notes, setNotes] = useState(issue?.notes ?? '');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [isSaving, setIsSaving] = useState(false);

    const isEditable = issue === null ? true : issue.is_editable && can.update;

    setLayoutProps({
        breadcrumbs: [
            { title: 'Barang Keluar', href: stockIssues.index() },
            {
                title: issue?.code ?? 'Dokumen Baru',
                href: issue ? stockIssues.edit(issue.id) : stockIssues.create(),
            },
        ],
    });

    const save = (): void => {
        const payload = {
            warehouse_id: warehouse.id,
            reason,
            reference_no: referenceNo || null,
            issue_date: issueDate,
            notes: notes || null,
            items: lines.map((line) => ({
                product_variant_id: line.product_variant_id,
                quantity: line.quantity,
                unit_cost: line.unit_cost,
            })),
        };

        const requestOptions = {
            preserveScroll: true,
            onStart: () => setIsSaving(true),
            onFinish: () => setIsSaving(false),
            onError: (received: Record<string, string>) => setErrors(received),
            onSuccess: () => setErrors({}),
        };

        if (issue === null) {
            router.post(stockIssues.store().url, payload, requestOptions);

            return;
        }

        router.put(stockIssues.update(issue.id).url, payload, requestOptions);
    };

    return (
        <>
            <Head
                title={`${issue?.code ?? 'Barang Keluar Baru'} · ${warehouse.name}`}
            />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={issue?.code ?? 'Barang Keluar Baru'}
                        description={`Gudang ${warehouse.name} · cabang ${branch.name}. Stok berkurang saat dokumen diposting, bukan saat disimpan.`}
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        <Button variant="outline" asChild>
                            <Link href={stockIssues.index()}>
                                <ArrowLeft aria-hidden />
                                Kembali
                            </Link>
                        </Button>
                        {issue && (
                            <StatusBadge
                                label={issue.status_label}
                                tone={STATUS_TONE[issue.status] ?? 'neutral'}
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
                                Alasan wajib diisi: jumlah yang berkurang tanpa
                                sebab bukan sesuatu yang bisa ditindaklanjuti.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="reason">Alasan</Label>
                                <Select
                                    value={reason}
                                    disabled={!isEditable}
                                    onValueChange={setReason}
                                >
                                    <SelectTrigger
                                        id="reason"
                                        aria-label="Alasan"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectGroup>
                                            {options.reasons.map((option) => (
                                                <SelectItem
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </SelectItem>
                                            ))}
                                        </SelectGroup>
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.reason} />
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
                                    onChange={(event) =>
                                        setReferenceNo(event.target.value)
                                    }
                                />
                                <InputError message={errors.reference_no} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="issue_date">
                                    Tanggal keluar
                                </Label>
                                <Input
                                    id="issue_date"
                                    type="date"
                                    value={issueDate}
                                    disabled={!isEditable}
                                    onChange={(event) =>
                                        setIssueDate(event.target.value)
                                    }
                                />
                                <InputError message={errors.issue_date} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="notes">
                                    Catatan
                                    {reason === REASON_NEEDING_NOTES ? (
                                        ''
                                    ) : (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            (opsional)
                                        </span>
                                    )}
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
                                {reason === REASON_NEEDING_NOTES && (
                                    <p className="text-xs text-muted-foreground">
                                        Alasan "Lainnya" perlu penjelasan. Tulis
                                        apa yang terjadi.
                                    </p>
                                )}
                                <InputError message={errors.notes} />
                            </div>

                            {issue && !issue.is_editable && (
                                <dl className="grid gap-1 border-t pt-4 text-xs text-muted-foreground">
                                    <div className="flex justify-between gap-2">
                                        <dt>Dibuat oleh</dt>
                                        <dd>{issue.created_by ?? '—'}</dd>
                                    </div>
                                    <div className="flex justify-between gap-2">
                                        <dt>Diposting oleh</dt>
                                        <dd>{issue.posted_by ?? '—'}</dd>
                                    </div>
                                    <div className="flex justify-between gap-2">
                                        <dt>Waktu posting</dt>
                                        <dd>
                                            {formatDateTime(issue.posted_at)}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-2">
                                        <dt>Total unit</dt>
                                        <dd className="tabular-nums">
                                            {formatNumber(issue.total_quantity)}
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
                                Jumlah selalu positif. Angka "tersedia" adalah
                                stok yang belum ditahan checkout — itulah yang
                                boleh dikeluarkan.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <StockLineEditor
                                lines={lines}
                                onChange={setLines}
                                variantOptions={variantOptions}
                                isEditable={isEditable}
                                showAvailability
                                showUnitCost={false}
                                errors={errors}
                            />
                        </CardContent>
                    </Card>
                </div>

                <div className="flex flex-wrap items-center justify-end gap-2">
                    {issue && can.cancel && issue.is_editable && (
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    stockIssues.cancel(issue.id).url,
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
                            {issue === null
                                ? 'Simpan Draf'
                                : 'Simpan Perubahan'}
                        </Button>
                    )}
                    {issue && can.post && (
                        <Button
                            disabled={issue.posting_blocked_reason !== null}
                            title={issue.posting_blocked_reason ?? undefined}
                            onClick={() =>
                                router.post(
                                    stockIssues.post(issue.id).url,
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
                            Posting & Kurangi Stok
                        </Button>
                    )}
                </div>

                {issue?.posting_blocked_reason && can.post && (
                    <p className="text-right text-xs text-muted-foreground">
                        {issue.posting_blocked_reason}
                    </p>
                )}
            </div>
        </>
    );
}
