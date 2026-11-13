import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { ArrowLeft, Send } from 'lucide-react';
import { useState } from 'react';
import { StockLineEditor } from '@/components/branch/stock-line-editor';
import { StockScopePicker } from '@/components/branch/stock-scope-picker';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
import stockTransfers from '@/routes/branch/stock-transfers';
import type {
    StockLineDraft,
    StockScopeOption,
    StockVariantOption,
    StockWorkspaceProps,
} from '@/types';

type CreateStockTransferProps = StockWorkspaceProps & {
    /** Every other active branch, any of which may be asked. */
    originOptions: StockScopeOption[];
    variantOptions: StockVariantOption[];
};

/**
 * Asking another branch for stock (P2-E3-T3).
 *
 * The request is made from the warehouse that will receive the goods, so the
 * variant picker shows *this* warehouse's figure — what is missing here. The
 * origin's shelves are not shown: that is the origin's business, and it
 * answers when it approves.
 *
 * Sending the request moves nothing. Stock leaves the origin when it ships and
 * arrives here when it is received.
 */
export default function CreateStockTransfer({
    branch,
    warehouse,
    warehouses,
    branches,
    may_choose_branch,
    originOptions,
    variantOptions,
}: CreateStockTransferProps) {
    const [lines, setLines] = useState<StockLineDraft[]>([]);
    const [fromBranchId, setFromBranchId] = useState('');
    const [notes, setNotes] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [isSending, setIsSending] = useState(false);

    setLayoutProps({
        breadcrumbs: [
            { title: 'Transfer Stok', href: stockTransfers.index() },
            { title: 'Minta Stok', href: stockTransfers.create() },
        ],
    });

    const moveTo = (next: Record<string, string | null>): void => {
        const query: Record<string, string> = {
            branch: String(branch.id),
            warehouse: String(warehouse.id),
        };

        for (const [key, value] of Object.entries(next)) {
            if (value === null) {
                delete query[key];
            } else {
                query[key] = value;
            }
        }

        // A different branch has different warehouses, so its own default
        // is the right starting point rather than a warehouse id it lacks.
        if ('branch' in next) {
            delete query.warehouse;
        }

        router.get(stockTransfers.create().url, query);
    };

    const send = (): void => {
        router.post(
            stockTransfers.store().url,
            {
                warehouse_id: warehouse.id,
                from_branch_id:
                    fromBranchId === '' ? null : Number(fromBranchId),
                notes: notes || null,
                items: lines.map((line) => ({
                    product_variant_id: line.product_variant_id,
                    quantity: line.quantity,
                })),
            },
            {
                preserveScroll: true,
                onStart: () => setIsSending(true),
                onFinish: () => setIsSending(false),
                onError: (received) => setErrors(received),
            },
        );
    };

    return (
        <>
            <Head title={`Minta Stok · ${warehouse.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Minta Stok dari Cabang Lain"
                        description={`Barang akan masuk ke ${warehouse.name}, cabang ${branch.name}, saat diterima.`}
                    />
                    <Button variant="outline" asChild>
                        <Link href={stockTransfers.index()}>
                            <ArrowLeft aria-hidden />
                            Kembali
                        </Link>
                    </Button>
                </div>

                <StockScopePicker
                    branch={branch}
                    warehouse={warehouse}
                    warehouses={warehouses}
                    branches={branches}
                    may_choose_branch={may_choose_branch}
                    onChange={moveTo}
                />

                {errors.warehouse_id && (
                    <p className="rounded-lg border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive">
                        {errors.warehouse_id}
                    </p>
                )}

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="lg:col-span-1">
                        <CardHeader>
                            <CardTitle>Dari mana</CardTitle>
                            <CardDescription>
                                Cabang asal yang memutuskan berapa yang bisa
                                dikirim, dan dari gudang mana.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="from_branch_id">
                                    Cabang asal
                                </Label>
                                <Select
                                    value={fromBranchId}
                                    onValueChange={setFromBranchId}
                                >
                                    <SelectTrigger
                                        id="from_branch_id"
                                        aria-label="Cabang asal"
                                    >
                                        <SelectValue placeholder="Pilih cabang…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectGroup>
                                            {originOptions.map((option) => (
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
                                {originOptions.length === 0 && (
                                    <p className="text-xs text-muted-foreground">
                                        Belum ada cabang aktif lain yang bisa
                                        dimintai stok.
                                    </p>
                                )}
                                <InputError message={errors.from_branch_id} />
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
                                    maxLength={1000}
                                    placeholder="Mis. untuk promo akhir pekan, mohon dikirim sebelum Jumat."
                                    onChange={(event) =>
                                        setNotes(event.target.value)
                                    }
                                />
                                <InputError message={errors.notes} />
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>Barang yang diminta</CardTitle>
                            <CardDescription>
                                Angka "di gudang" adalah stok {warehouse.name}{' '}
                                saat ini, untuk menimbang berapa yang perlu
                                diminta.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <StockLineEditor
                                lines={lines}
                                onChange={setLines}
                                variantOptions={variantOptions}
                                isEditable
                                showUnitCost={false}
                                errors={errors}
                            />
                        </CardContent>
                    </Card>
                </div>

                <div className="flex justify-end">
                    <Button
                        disabled={isSending || lines.length === 0}
                        onClick={send}
                    >
                        <Send aria-hidden />
                        Kirim Permintaan
                    </Button>
                </div>
            </div>
        </>
    );
}
