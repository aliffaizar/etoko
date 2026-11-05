import { router } from '@inertiajs/react';
import { Link2, Sparkles, X } from 'lucide-react';
import { useId, useMemo, useRef, useState } from 'react';
import ProductVariantController from '@/actions/App/Http/Controllers/Admin/ProductVariantController';
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
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatNumber } from '@/lib/format';
import { index as attributesIndex } from '@/routes/admin/product-attributes';
import { Link } from '@inertiajs/react';
import type { ProductVariantLimits, VariantGeneratorAttribute } from '@/types';

type VariantGeneratorCardProps = {
    productId: number;
    attributes: VariantGeneratorAttribute[];
    limits: ProductVariantLimits;
};

/** One row of the builder: an attribute and the values picked under it. */
type OptionGroup = {
    /**
     * React key only, so a group keeps its identity when a sibling above it is
     * removed. Never put this in the DOM: the counter advances on the client
     * and not on the server, so an id built from it would differ between the
     * SSR-rendered HTML and the hydrated tree. DOM ids come from `useId()`.
     */
    key: number;
    attributeId: number | null;
    valueIds: number[];
};

/**
 * Builds a product's variants from the options it varies by (P1-E3-T6).
 *
 * The combination count is shown before anything is created, because that is
 * the number the user is actually deciding: three colours and four sizes is
 * twelve SKUs to price and stock, and it is easier to reconsider here than to
 * delete twelve variants afterwards.
 *
 * Generating is safe to repeat: combinations the product already has are left
 * untouched, so adding one more colour and generating again adds that colour
 * and changes nothing else.
 */
export function VariantGeneratorCard({
    productId,
    attributes,
    limits,
}: VariantGeneratorCardProps) {
    // SSR-stable prefix for the field ids; `useId()` is what React guarantees
    // matches between the server render and hydration.
    const fieldId = useId();
    const nextGroupKey = useRef(1);
    const newGroup = (): OptionGroup => ({
        key: nextGroupKey.current++,
        attributeId: null,
        valueIds: [],
    });

    const [groups, setGroups] = useState<OptionGroup[]>(() => [
        { key: 0, attributeId: null, valueIds: [] },
    ]);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>(undefined);

    const usedAttributeIds = groups
        .map((group) => group.attributeId)
        .filter((id): id is number => id !== null);

    const combinationCount = useMemo(
        () =>
            groups
                .filter((group) => group.valueIds.length > 0)
                .reduce((total, group) => total * group.valueIds.length, 1),
        [groups],
    );

    const readyGroups = groups.filter(
        (group) => group.attributeId !== null && group.valueIds.length > 0,
    );
    const isReady = readyGroups.length > 0;
    const exceedsLimit = isReady && combinationCount > limits.max_combinations;

    const updateGroup = (key: number, patch: Partial<OptionGroup>): void => {
        setGroups((current) =>
            current.map((group) =>
                group.key === key ? { ...group, ...patch } : group,
            ),
        );
    };

    const generate = (): void => {
        setError(undefined);

        router.post(
            ProductVariantController.generate(productId).url,
            {
                options: readyGroups.map((group) => ({
                    attribute_id: group.attributeId,
                    value_ids: group.valueIds,
                })),
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errors) =>
                    setError(
                        Object.values(errors)[0] ??
                            'Varian gagal dibuat. Periksa pilihan atribut.',
                    ),
                onSuccess: () => setGroups([newGroup()]),
            },
        );
    };

    if (attributes.length === 0) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>Buat varian dari atribut</CardTitle>
                    <CardDescription>
                        Belum ada atribut di katalog. Atribut seperti Warna atau
                        Ukuran dipakai bersama semua produk dan dibuat di
                        layarnya sendiri.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Button variant="secondary" asChild>
                        <Link href={attributesIndex()}>
                            <Link2 aria-hidden />
                            Kelola Atribut Produk
                        </Link>
                    </Button>
                </CardContent>
            </Card>
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Buat varian dari atribut</CardTitle>
                <CardDescription>
                    Pilih atribut beserta nilainya, lalu buat semua kombinasinya
                    sekaligus. Kombinasi yang sudah ada dibiarkan apa adanya —
                    harga dan SKU yang sudah diisi tidak akan tertimpa.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <div className="flex flex-col gap-3">
                    {groups.map((group, index) => {
                        const attribute = attributes.find(
                            (candidate) => candidate.id === group.attributeId,
                        );

                        return (
                            <div
                                key={group.key}
                                className="flex flex-col gap-3 rounded-lg border p-3 sm:flex-row sm:items-start"
                            >
                                <div className="grid gap-2 sm:w-56 sm:shrink-0">
                                    <Label
                                        htmlFor={`${fieldId}-attribute-${index}`}
                                    >
                                        Atribut {index + 1}
                                    </Label>
                                    <Select
                                        value={
                                            group.attributeId === null
                                                ? undefined
                                                : String(group.attributeId)
                                        }
                                        onValueChange={(next) =>
                                            updateGroup(group.key, {
                                                attributeId: Number(next),
                                                valueIds: [],
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            id={`${fieldId}-attribute-${index}`}
                                            className="w-full"
                                        >
                                            <SelectValue placeholder="Pilih atribut…" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {attributes
                                                .filter(
                                                    (candidate) =>
                                                        candidate.id ===
                                                            group.attributeId ||
                                                        !usedAttributeIds.includes(
                                                            candidate.id,
                                                        ),
                                                )
                                                .map((candidate) => (
                                                    <SelectItem
                                                        key={candidate.id}
                                                        value={String(
                                                            candidate.id,
                                                        )}
                                                    >
                                                        {candidate.name}
                                                    </SelectItem>
                                                ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="grid min-w-0 flex-1 gap-2">
                                    <Label>Nilai yang dipakai</Label>
                                    {attribute === undefined ? (
                                        <p className="text-sm text-muted-foreground">
                                            Pilih atribut lebih dahulu.
                                        </p>
                                    ) : attribute.values.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            Atribut {attribute.name} belum punya
                                            nilai.
                                        </p>
                                    ) : (
                                        <div className="flex flex-wrap gap-2">
                                            {attribute.values.map((value) => {
                                                const isPicked =
                                                    group.valueIds.includes(
                                                        value.id,
                                                    );

                                                return (
                                                    <button
                                                        key={value.id}
                                                        type="button"
                                                        aria-pressed={isPicked}
                                                        className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none ${
                                                            isPicked
                                                                ? 'border-primary bg-primary text-primary-foreground'
                                                                : 'bg-background hover:bg-muted'
                                                        }`}
                                                        onClick={() =>
                                                            updateGroup(
                                                                group.key,
                                                                {
                                                                    valueIds:
                                                                        isPicked
                                                                            ? group.valueIds.filter(
                                                                                  (
                                                                                      id,
                                                                                  ) =>
                                                                                      id !==
                                                                                      value.id,
                                                                              )
                                                                            : [
                                                                                  ...group.valueIds,
                                                                                  value.id,
                                                                              ],
                                                                },
                                                            )
                                                        }
                                                    >
                                                        {value.color_hex && (
                                                            <span
                                                                aria-hidden
                                                                className="size-3 shrink-0 rounded-full border"
                                                                style={{
                                                                    backgroundColor:
                                                                        value.color_hex,
                                                                }}
                                                            />
                                                        )}
                                                        {value.label}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    )}
                                </div>

                                {groups.length > 1 && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Hapus atribut ${index + 1}`}
                                        onClick={() =>
                                            setGroups((current) =>
                                                current.filter(
                                                    (candidate) =>
                                                        candidate.key !==
                                                        group.key,
                                                ),
                                            )
                                        }
                                    >
                                        <X aria-hidden />
                                    </Button>
                                )}
                            </div>
                        );
                    })}
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        disabled={
                            groups.length >= attributes.length ||
                            groups.length >= 5
                        }
                        onClick={() =>
                            setGroups((current) => [...current, newGroup()])
                        }
                    >
                        Tambah atribut
                    </Button>
                    {groups.length >= 5 && (
                        <p className="text-xs text-muted-foreground">
                            Satu varian paling banyak dibentuk oleh 5 atribut.
                        </p>
                    )}
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/40 p-3">
                    <p className="text-sm">
                        {isReady ? (
                            <>
                                Menghasilkan{' '}
                                <strong className="tabular-nums">
                                    {formatNumber(combinationCount)}
                                </strong>{' '}
                                kombinasi
                                {exceedsLimit && (
                                    <span className="text-destructive">
                                        {' '}
                                        — melewati batas{' '}
                                        {formatNumber(
                                            limits.max_combinations,
                                        )}{' '}
                                        varian per produk
                                    </span>
                                )}
                            </>
                        ) : (
                            <span className="text-muted-foreground">
                                Pilih minimal satu atribut beserta nilainya.
                            </span>
                        )}
                    </p>
                    <Button
                        type="button"
                        disabled={!isReady || exceedsLimit || processing}
                        onClick={generate}
                    >
                        <Sparkles aria-hidden />
                        Buat Varian
                    </Button>
                </div>

                <InputError message={error} />
            </CardContent>
        </Card>
    );
}
