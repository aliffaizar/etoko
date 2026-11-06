import { Form, router, usePage } from '@inertiajs/react';
import { Check, LocateFixed, MapPin, Store } from 'lucide-react';
import { useState } from 'react';
import BranchController from '@/actions/App/Http/Controllers/Shop/BranchController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import type { ShopBranchOption, ShopContext } from '@/types';

/**
 * How long to wait for the browser's position before giving up.
 *
 * Short on purpose: somebody who allowed location access expects an answer,
 * and a picker that sits spinning is worse than one that says "pick manually".
 */
const GEOLOCATION_TIMEOUT_MS = 10_000;

/**
 * Where the shopper is shopping from (P1-E5-T2).
 *
 * Prices are the catalogue's and do not move with the branch (ADR-031); what
 * the branch decides is **stock** and where an order can be collected. That is
 * still worth having in the header rather than buried in a settings page,
 * because it is what tells a shopper whether they can have the thing today.
 * Two ways to answer it:
 *
 * - **Manually**, from the list of branches that are open. Each one says what
 *   it supports, so a shopper who needs delivery can tell before choosing.
 * - **By location**, which asks the browser for a position and lets the server
 *   rank the branches. Nothing is stored but the branch id: the coordinates
 *   are used to pick and then discarded.
 *
 * A first-time visitor is shown the main branch and the trigger says
 * "Pilih cabang", because implying they already chose would be a lie about
 * whose prices they are looking at.
 */
export function BranchPicker({ className }: { className?: string }) {
    const shop = usePage().props.shop as ShopContext | undefined;
    const [isOpen, setIsOpen] = useState(false);

    if (!shop?.branch) {
        return null;
    }

    const { branch, branches, has_chosen_branch: hasChosen } = shop;

    return (
        <Dialog open={isOpen} onOpenChange={setIsOpen}>
            <DialogTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    className={cn(
                        'h-auto justify-start gap-2 py-1.5',
                        className,
                    )}
                >
                    <MapPin
                        className="size-4 shrink-0 text-primary"
                        aria-hidden
                    />
                    <span className="flex min-w-0 flex-col items-start leading-tight">
                        <span className="text-[11px] text-muted-foreground">
                            {hasChosen ? 'Cek stok di' : 'Pilih cabang'}
                        </span>
                        <span className="max-w-[12rem] truncate text-xs font-medium">
                            {branch.name}
                        </span>
                    </span>
                </Button>
            </DialogTrigger>

            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Pilih cabang</DialogTitle>
                    <DialogDescription>
                        Harga sama di semua cabang. Yang mengikuti pilihan di
                        sini adalah ketersediaan stok dan tempat pengambilan
                        pesanan.
                    </DialogDescription>
                </DialogHeader>

                <NearestBranchButton
                    canRank={branches.some((option) => option.has_coordinates)}
                    onDone={() => setIsOpen(false)}
                />

                <ul className="flex flex-col gap-2">
                    {branches.map((option) => (
                        <li key={option.id}>
                            <BranchOptionRow
                                option={option}
                                isCurrent={option.id === branch.id}
                                onChosen={() => setIsOpen(false)}
                            />
                        </li>
                    ))}
                </ul>
            </DialogContent>
        </Dialog>
    );
}

function BranchOptionRow({
    option,
    isCurrent,
    onChosen,
}: {
    option: ShopBranchOption;
    isCurrent: boolean;
    onChosen: () => void;
}) {
    return (
        <Form
            {...BranchController.update.form()}
            options={{ preserveScroll: true }}
            onSuccess={onChosen}
        >
            {({ processing }) => (
                <>
                    <input type="hidden" name="branch_id" value={option.id} />
                    <button
                        type="submit"
                        disabled={processing || isCurrent}
                        className={cn(
                            'flex w-full items-start gap-3 rounded-lg border p-3 text-left transition-colors',
                            isCurrent
                                ? 'border-primary bg-primary/5'
                                : 'hover:border-primary/40 hover:bg-accent',
                            processing && 'opacity-60',
                        )}
                    >
                        <Store
                            className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                            aria-hidden
                        />
                        <span className="min-w-0 flex-1">
                            <span className="flex items-center gap-2">
                                <span className="truncate text-sm font-medium">
                                    {option.name}
                                </span>
                                {isCurrent && (
                                    <span className="inline-flex items-center gap-1 text-xs text-primary">
                                        <Check className="size-3" aria-hidden />
                                        Dipilih
                                    </span>
                                )}
                            </span>
                            <span className="mt-0.5 block truncate text-xs text-muted-foreground">
                                {option.address_line}
                                {option.city_name
                                    ? `, ${option.city_name}`
                                    : ''}
                            </span>
                            <span className="mt-1 block text-xs text-muted-foreground">
                                {fulfillmentLabel(option)}
                            </span>
                        </span>
                    </button>
                </>
            )}
        </Form>
    );
}

/**
 * Asks the browser where the shopper is and lets the server rank the branches.
 *
 * Posted with `router.post` rather than through `<Form>`: there is no field to
 * fill in and nothing to validate on screen — the payload comes from a browser
 * API in a callback, so there is no form state for `<Form>` to own.
 *
 * The position never reaches this component's state: it is submitted and
 * forgotten. When no open branch has coordinates there is nothing to rank, so
 * the button is not offered at all rather than failing after a permission
 * prompt the shopper had no reason to answer.
 */
function NearestBranchButton({
    canRank,
    onDone,
}: {
    canRank: boolean;
    onDone: () => void;
}) {
    const [isLocating, setIsLocating] = useState(false);
    const [error, setError] = useState<string | null>(null);

    if (!canRank) {
        return (
            <p className="rounded-lg border border-dashed p-3 text-xs text-muted-foreground">
                Cabang terdekat belum bisa dihitung karena titik lokasi cabang
                belum diisi. Pilih cabang dari daftar di bawah.
            </p>
        );
    }

    const locate = () => {
        setError(null);

        if (!('geolocation' in navigator)) {
            setError(
                'Peramban ini tidak mendukung deteksi lokasi. Pilih cabang secara manual.',
            );

            return;
        }

        setIsLocating(true);

        navigator.geolocation.getCurrentPosition(
            (position) => {
                router.post(
                    BranchController.nearest().url,
                    {
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                    },
                    {
                        preserveScroll: true,
                        onSuccess: onDone,
                        onFinish: () => setIsLocating(false),
                    },
                );
            },
            () => {
                setIsLocating(false);
                setError(
                    'Lokasi tidak bisa dibaca. Izinkan akses lokasi di peramban, atau pilih cabang secara manual.',
                );
            },
            { timeout: GEOLOCATION_TIMEOUT_MS },
        );
    };

    return (
        <div className="flex flex-col gap-2">
            <Button
                type="button"
                variant="secondary"
                disabled={isLocating}
                onClick={locate}
            >
                {isLocating ? (
                    <Spinner aria-hidden />
                ) : (
                    <LocateFixed aria-hidden />
                )}
                Gunakan cabang terdekat
            </Button>

            {error && <p className="text-xs text-destructive">{error}</p>}
        </div>
    );
}

function fulfillmentLabel(option: ShopBranchOption): string {
    if (option.supports_delivery && option.supports_pickup) {
        return 'Bisa dikirim atau diambil di cabang';
    }

    if (option.supports_delivery) {
        return 'Hanya pengiriman';
    }

    if (option.supports_pickup) {
        return 'Hanya ambil di cabang';
    }

    return 'Belum menerima pesanan online';
}
