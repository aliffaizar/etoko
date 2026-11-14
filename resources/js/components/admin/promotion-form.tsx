import { Form } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { PickList } from '@/components/admin/voucher-form';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import type { PromotionDetail, PromotionFormOptions } from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

type PromotionFormProps = {
    /** Wayfinder form props, e.g. `PromotionController.store.form()`. */
    action: RouteFormDefinition<'post' | 'put'>;
    options: PromotionFormOptions;
    /** Absent when creating. */
    promotion?: PromotionDetail;
    submitLabel: string;
    secondaryAction?: ReactNode;
};

/** `2026-11-14T10:00` in the browser's clock, for a `datetime-local` input. */
function toLocalInput(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);
    const pad = (n: number): string => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/**
 * Create and edit share this form (P2-E8-T2, ADR-067).
 *
 * The hint under the type says what the value means for that type, because a
 * percentage of 10 and a rupiah amount of 10 are very different promotions.
 */
export function PromotionForm({
    action,
    options,
    promotion,
    submitLabel,
    secondaryAction,
}: PromotionFormProps) {
    const [type, setType] = useState<string>(promotion?.type ?? 'percentage');
    const [isActive, setIsActive] = useState(promotion?.is_active ?? true);
    const [productIds, setProductIds] = useState<number[]>(
        promotion?.product_ids ?? [],
    );
    const [branchIds, setBranchIds] = useState<number[]>(
        promotion?.branch_ids ?? [],
    );

    return (
        <Form
            {...action}
            className="flex flex-col gap-8"
            resetOnSuccess={false}
        >
            {({ processing, errors }) => (
                <>
                    <section className="grid gap-6 md:grid-cols-2">
                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="name">Nama promo</Label>
                            <Input
                                id="name"
                                name="name"
                                defaultValue={promotion?.name ?? ''}
                                required
                                maxLength={255}
                                placeholder="Diskon ulang tahun toko"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid content-start gap-2">
                            <Label htmlFor="type">Jenis</Label>
                            <input type="hidden" name="type" value={type} />
                            <Select value={type} onValueChange={setType}>
                                <SelectTrigger id="type">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        {options.types.map((option) => (
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
                            <InputError message={errors.type} />
                        </div>

                        <div className="grid content-start gap-2">
                            <Label htmlFor="value">
                                {type === 'percentage'
                                    ? 'Potongan (%)'
                                    : 'Potongan (Rp)'}
                            </Label>
                            <Input
                                id="value"
                                name="value"
                                type="number"
                                min={1}
                                max={type === 'percentage' ? 100 : undefined}
                                required
                                defaultValue={promotion?.value ?? ''}
                            />
                            <p className="text-xs text-muted-foreground">
                                {type === 'percentage'
                                    ? 'Dihitung dari harga katalog. Pembulatan ke bawah.'
                                    : 'Dikurangkan dari harga katalog per unit. Harga tidak bisa di bawah nol.'}
                            </p>
                            <InputError message={errors.value} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="priority">Prioritas</Label>
                            <Input
                                id="priority"
                                name="priority"
                                type="number"
                                min={-1000}
                                max={1000}
                                required
                                defaultValue={promotion?.priority ?? 0}
                            />
                            <p className="text-xs text-muted-foreground">
                                Bila dua promo berlaku untuk produk yang sama,
                                yang lebih tinggi menang.
                            </p>
                            <InputError message={errors.priority} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="starts_at">
                                Mulai{' '}
                                <span className="text-muted-foreground">
                                    (opsional)
                                </span>
                            </Label>
                            <Input
                                id="starts_at"
                                name="starts_at"
                                type="datetime-local"
                                defaultValue={toLocalInput(
                                    promotion?.starts_at,
                                )}
                            />
                            <InputError message={errors.starts_at} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="ends_at">
                                Berakhir{' '}
                                <span className="text-muted-foreground">
                                    (opsional)
                                </span>
                            </Label>
                            <Input
                                id="ends_at"
                                name="ends_at"
                                type="datetime-local"
                                defaultValue={toLocalInput(promotion?.ends_at)}
                            />
                            <InputError message={errors.ends_at} />
                        </div>
                    </section>

                    <section className="grid gap-6 md:grid-cols-2">
                        <div className="grid content-start gap-2">
                            <PickList
                                name="product_ids"
                                label="Produk"
                                options={options.products}
                                selected={productIds}
                                onChange={setProductIds}
                                searchable
                                hint="Harga produk yang dipilih ikut berubah selama promo berjalan."
                            />
                            <InputError message={errors.product_ids} />
                        </div>

                        <div className="grid content-start gap-2">
                            <PickList
                                name="branch_ids"
                                label="Cabang"
                                options={options.branches}
                                selected={branchIds}
                                onChange={setBranchIds}
                                hint="Kosongkan agar berlaku di semua cabang dan di toko online."
                            />
                            <InputError message={errors.branch_ids} />
                        </div>
                    </section>

                    <div className="flex items-start gap-3 rounded-lg border p-4 md:max-w-md">
                        <input
                            type="hidden"
                            name="is_active"
                            value={isActive ? '1' : '0'}
                        />
                        <Checkbox
                            id="is_active"
                            checked={isActive}
                            onCheckedChange={(next) =>
                                setIsActive(next === true)
                            }
                        />
                        <div className="grid gap-1">
                            <Label
                                htmlFor="is_active"
                                className="cursor-pointer"
                            >
                                Promo aktif
                            </Label>
                            <p className="text-xs text-muted-foreground">
                                Promo nonaktif tidak mengubah harga, walau
                                periodenya berlaku.
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={processing}>
                            {submitLabel}
                        </Button>
                        {secondaryAction}
                    </div>
                </>
            )}
        </Form>
    );
}
