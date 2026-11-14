import { Form } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useMemo, useState } from 'react';
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
import { Textarea } from '@/components/ui/textarea';
import type {
    VoucherAppliesToValue,
    VoucherChannelValue,
    VoucherDetail,
    VoucherFormOptions,
    VoucherPickOption,
    VoucherTypeValue,
} from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

type VoucherFormProps = {
    /** Wayfinder form props, e.g. `VoucherController.store.form()`. */
    action: RouteFormDefinition<'post' | 'put'>;
    options: VoucherFormOptions;
    /** Absent when creating. */
    voucher?: VoucherDetail;
    submitLabel: string;
    secondaryAction?: ReactNode;
};

/**
 * `2026-11-14T10:00` in the browser's clock, for a `datetime-local` input.
 * The server reads it in the shop's timezone, which is the one staff work in.
 */
function toLocalInput(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);
    const pad = (n: number): string => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/**
 * Create and edit share this form (P2-E5-T2).
 *
 * The fields that depend on the type — what "nilai" means, whether there is a
 * ceiling — follow the select, and the category/product pickers appear only
 * when the voucher is narrowed to them. The server empties the list a voucher
 * is not narrowed to, so what is hidden here is never saved.
 */
export function VoucherForm({
    action,
    options,
    voucher,
    submitLabel,
    secondaryAction,
}: VoucherFormProps) {
    const [type, setType] = useState<VoucherTypeValue>(
        voucher?.type ?? 'percentage',
    );
    const [appliesTo, setAppliesTo] = useState<VoucherAppliesToValue>(
        voucher?.applies_to ?? 'all',
    );
    const [channel, setChannel] = useState<VoucherChannelValue>(
        voucher?.channel ?? 'all',
    );
    const [isActive, setIsActive] = useState(voucher?.is_active ?? true);
    const [branchIds, setBranchIds] = useState<number[]>(
        voucher?.branch_ids ?? [],
    );
    const [categoryIds, setCategoryIds] = useState<number[]>(
        voucher?.category_ids ?? [],
    );
    const [productIds, setProductIds] = useState<number[]>(
        voucher?.product_ids ?? [],
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
                        <div className="grid gap-2">
                            <Label htmlFor="code">Kode voucher</Label>
                            <Input
                                id="code"
                                name="code"
                                defaultValue={voucher?.code ?? ''}
                                required
                                maxLength={32}
                                placeholder="HEMAT10"
                                className="font-mono uppercase"
                            />
                            <p className="text-xs text-muted-foreground">
                                Huruf, angka, tanda hubung, atau garis bawah.
                                Disimpan dalam huruf besar.
                            </p>
                            <InputError message={errors.code} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="name">Nama voucher</Label>
                            <Input
                                id="name"
                                name="name"
                                defaultValue={voucher?.name ?? ''}
                                required
                                maxLength={255}
                                placeholder="Diskon akhir pekan"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="description">
                                Deskripsi{' '}
                                <span className="text-muted-foreground">
                                    (opsional)
                                </span>
                            </Label>
                            <Textarea
                                id="description"
                                name="description"
                                defaultValue={voucher?.description ?? ''}
                                rows={2}
                                placeholder="Catatan internal atau syarat singkat."
                            />
                            <InputError message={errors.description} />
                        </div>
                    </section>

                    <section className="grid gap-6 md:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="type">Jenis</Label>
                            <input type="hidden" name="type" value={type} />
                            <Select
                                value={type}
                                onValueChange={(next) =>
                                    setType(next as VoucherTypeValue)
                                }
                            >
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

                        {type !== 'free_shipping' && (
                            <div className="grid gap-2">
                                <Label htmlFor="value">
                                    {type === 'percentage'
                                        ? 'Diskon (%)'
                                        : 'Potongan (Rp)'}
                                </Label>
                                <Input
                                    id="value"
                                    name="value"
                                    type="number"
                                    min={1}
                                    max={
                                        type === 'percentage' ? 100 : undefined
                                    }
                                    defaultValue={voucher?.value || ''}
                                    required
                                />
                                <InputError message={errors.value} />
                            </div>
                        )}

                        {type !== 'fixed' && (
                            <div className="grid gap-2">
                                <Label htmlFor="max_discount">
                                    {type === 'free_shipping'
                                        ? 'Ongkir ditanggung maks. (Rp)'
                                        : 'Potongan maksimal (Rp)'}
                                </Label>
                                <Input
                                    id="max_discount"
                                    name="max_discount"
                                    type="number"
                                    min={1}
                                    defaultValue={voucher?.max_discount ?? ''}
                                    placeholder="Tanpa batas"
                                />
                                <InputError message={errors.max_discount} />
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="min_order_amount">
                                Minimal belanja (Rp)
                            </Label>
                            <Input
                                id="min_order_amount"
                                name="min_order_amount"
                                type="number"
                                min={0}
                                defaultValue={voucher?.min_order_amount ?? 0}
                            />
                            <p className="text-xs text-muted-foreground">
                                Dihitung dari produk yang termasuk voucher.
                            </p>
                            <InputError message={errors.min_order_amount} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="quota">Kuota total</Label>
                            <Input
                                id="quota"
                                name="quota"
                                type="number"
                                min={Math.max(voucher?.used_count ?? 0, 1)}
                                defaultValue={voucher?.quota ?? ''}
                                placeholder="Tanpa batas"
                            />
                            {voucher && (
                                <p className="text-xs text-muted-foreground">
                                    Sudah terpakai {voucher.used_count}.
                                </p>
                            )}
                            <InputError message={errors.quota} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="per_user_limit">
                                Batas per pelanggan
                            </Label>
                            <Input
                                id="per_user_limit"
                                name="per_user_limit"
                                type="number"
                                min={0}
                                defaultValue={voucher?.per_user_limit ?? 1}
                                required
                            />
                            <p className="text-xs text-muted-foreground">
                                0 = tanpa batas.
                            </p>
                            <InputError message={errors.per_user_limit} />
                        </div>
                    </section>

                    <section className="grid gap-6 md:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="starts_at">
                                Mulai berlaku{' '}
                                <span className="text-muted-foreground">
                                    (opsional)
                                </span>
                            </Label>
                            <Input
                                id="starts_at"
                                name="starts_at"
                                type="datetime-local"
                                defaultValue={toLocalInput(voucher?.starts_at)}
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
                                defaultValue={toLocalInput(voucher?.ends_at)}
                            />
                            <InputError message={errors.ends_at} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="channel">Kanal</Label>
                            <input
                                type="hidden"
                                name="channel"
                                value={channel}
                            />
                            <Select
                                value={channel}
                                onValueChange={(next) =>
                                    setChannel(next as VoucherChannelValue)
                                }
                            >
                                <SelectTrigger id="channel">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        {options.channels.map((option) => (
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
                            <InputError message={errors.channel} />
                        </div>
                    </section>

                    <section className="grid gap-6 md:grid-cols-2">
                        <div className="grid content-start gap-2">
                            <Label htmlFor="applies_to">Berlaku untuk</Label>
                            <input
                                type="hidden"
                                name="applies_to"
                                value={appliesTo}
                            />
                            <Select
                                value={appliesTo}
                                onValueChange={(next) =>
                                    setAppliesTo(next as VoucherAppliesToValue)
                                }
                            >
                                <SelectTrigger id="applies_to">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        {options.applies_to.map((option) => (
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
                            <InputError message={errors.applies_to} />

                            {appliesTo === 'category' && (
                                <>
                                    <PickList
                                        name="category_ids"
                                        label="Kategori"
                                        options={options.categories}
                                        selected={categoryIds}
                                        onChange={setCategoryIds}
                                        hint="Subkategori ikut tercakup."
                                    />
                                    <InputError message={errors.category_ids} />
                                </>
                            )}

                            {appliesTo === 'product' && (
                                <>
                                    <PickList
                                        name="product_ids"
                                        label="Produk"
                                        options={options.products}
                                        selected={productIds}
                                        onChange={setProductIds}
                                        searchable
                                    />
                                    <InputError message={errors.product_ids} />
                                </>
                            )}
                        </div>

                        <div className="grid content-start gap-2">
                            <PickList
                                name="branch_ids"
                                label="Cabang"
                                options={options.branches}
                                selected={branchIds}
                                onChange={setBranchIds}
                                hint="Kosongkan agar berlaku di semua cabang."
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
                            <Label htmlFor="is_active">Aktif</Label>
                            <p className="text-xs text-muted-foreground">
                                Voucher nonaktif ditolak di keranjang dan
                                checkout, walau masih dalam periode.
                            </p>
                            <InputError message={errors.is_active} />
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
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

/**
 * A checkbox list that submits the ticked ids as `name[]`, optionally with a
 * filter box for long lists such as products.
 */
export function PickList({
    name,
    label,
    options,
    selected,
    onChange,
    hint,
    searchable = false,
}: {
    name: string;
    label: string;
    options: VoucherPickOption[];
    selected: number[];
    onChange: (next: number[]) => void;
    hint?: string;
    searchable?: boolean;
}) {
    const [query, setQuery] = useState('');

    const visible = useMemo(() => {
        const needle = query.trim().toLowerCase();

        return needle === ''
            ? options
            : options.filter((option) =>
                  option.label.toLowerCase().includes(needle),
              );
    }, [options, query]);

    const toggle = (id: number, checked: boolean): void => {
        onChange(
            checked
                ? [...selected, id]
                : selected.filter((value) => value !== id),
        );
    };

    return (
        <fieldset className="grid gap-2">
            <legend className="mb-2 text-sm font-medium">
                {label}{' '}
                <span className="font-normal text-muted-foreground">
                    ({selected.length} dipilih)
                </span>
            </legend>

            {selected.map((id) => (
                <input key={id} type="hidden" name={`${name}[]`} value={id} />
            ))}

            {searchable && (
                <Input
                    type="search"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder={`Cari ${label.toLowerCase()}…`}
                    aria-label={`Cari ${label.toLowerCase()}`}
                />
            )}

            <div className="max-h-64 overflow-y-auto rounded-md border p-2">
                {visible.length === 0 ? (
                    <p className="p-2 text-sm text-muted-foreground">
                        Tidak ada yang cocok.
                    </p>
                ) : (
                    visible.map((option) => {
                        const id = `${name}-${option.value}`;

                        return (
                            <div
                                key={option.value}
                                className="flex items-center gap-2 rounded px-2 py-1.5 hover:bg-muted"
                                style={{
                                    paddingInlineStart: `${((option.depth ?? 1) - 1) * 1.25 + 0.5}rem`,
                                }}
                            >
                                <Checkbox
                                    id={id}
                                    checked={selected.includes(option.value)}
                                    onCheckedChange={(next) =>
                                        toggle(option.value, next === true)
                                    }
                                />
                                <Label
                                    htmlFor={id}
                                    className="flex-1 cursor-pointer font-normal"
                                >
                                    {option.label}
                                </Label>
                            </div>
                        );
                    })
                )}
            </div>

            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
        </fieldset>
    );
}
