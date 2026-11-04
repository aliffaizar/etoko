import { Form } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
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
import type { BranchDetail, BranchFormOptions } from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

/**
 * The fields this form submits, so Inertia can type its validation errors.
 * Everything travels as a string because it is a plain HTML form.
 */
type BranchFormData = {
    code: string;
    name: string;
    slug: string;
    description: string;
    phone: string;
    email: string;
    province_name: string;
    city_name: string;
    district_name: string;
    postal_code: string;
    address_line: string;
    latitude: string;
    longitude: string;
    status: string;
    supports_delivery: string;
    supports_pickup: string;
    is_default: string;
    sort_order: string;
};

type BranchFormProps = {
    /** Wayfinder form props, e.g. `BranchController.store.form()`. */
    action: RouteFormDefinition<'post' | 'put'>;
    options: BranchFormOptions;
    /** Absent when creating. */
    branch?: BranchDetail;
    submitLabel: string;
    secondaryAction?: ReactNode;
};

/**
 * Create and edit differ only in their defaults and endpoint, so both pages
 * render this one component.
 *
 * Two fields appear only when editing. The slug is derived from the name by
 * `CreateBranch`, and the "cabang utama" flag is decided there too — the very
 * first branch becomes the storefront's fallback. Both can be changed
 * afterwards, and the server refuses to leave the storefront without a
 * fallback either way.
 *
 * The address is plain text in Phase 1: the `regions` dataset arrives in
 * `P2-E2-T3`, and the snapshot columns are what the storefront reads anyway.
 */
export function BranchForm({
    action,
    options,
    branch,
    submitLabel,
    secondaryAction,
}: BranchFormProps) {
    const isEditing = branch !== undefined;
    const [status, setStatus] = useState<string>(branch?.status ?? 'active');
    const [supportsDelivery, setSupportsDelivery] = useState(
        branch?.supports_delivery ?? true,
    );
    const [supportsPickup, setSupportsPickup] = useState(
        branch?.supports_pickup ?? true,
    );
    const [isDefault, setIsDefault] = useState(branch?.is_default ?? false);

    return (
        <Form<BranchFormData>
            {...action}
            className="flex flex-col gap-8"
            resetOnSuccess={false}
        >
            {({ processing, errors }) => (
                <>
                    <section className="grid gap-6 md:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="code">Kode cabang</Label>
                            <Input
                                id="code"
                                name="code"
                                defaultValue={branch?.code ?? ''}
                                required
                                maxLength={20}
                                placeholder="JKT-01"
                                className="uppercase"
                            />
                            <p className="text-xs text-muted-foreground">
                                Huruf kapital, angka, dan tanda hubung. Dipakai
                                di nomor dokumen dan laporan.
                            </p>
                            <InputError message={errors.code} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="name">Nama cabang</Label>
                            <Input
                                id="name"
                                name="name"
                                defaultValue={branch?.name ?? ''}
                                required
                                placeholder="Cabang Jakarta Pusat"
                            />
                            <InputError message={errors.name} />
                        </div>

                        {isEditing && (
                            <div className="grid gap-2">
                                <Label htmlFor="slug">Slug</Label>
                                <Input
                                    id="slug"
                                    name="slug"
                                    defaultValue={branch.slug}
                                    required
                                    placeholder="cabang-jakarta-pusat"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Bagian alamat cabang ini di storefront.
                                    Mengubahnya mengubah tautan yang sudah
                                    tersebar.
                                </p>
                                <InputError message={errors.slug} />
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="status">Status</Label>
                            <input type="hidden" name="status" value={status} />
                            <Select value={status} onValueChange={setStatus}>
                                <SelectTrigger id="status">
                                    <SelectValue placeholder="Pilih status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        {options.statuses.map((option) => (
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
                            <p className="text-xs text-muted-foreground">
                                Hanya cabang berstatus Aktif yang menerima
                                pesanan baru.
                            </p>
                            <InputError message={errors.status} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="phone">
                                Nomor telepon{' '}
                                <span className="text-muted-foreground">
                                    (opsional)
                                </span>
                            </Label>
                            <Input
                                id="phone"
                                name="phone"
                                defaultValue={branch?.phone ?? ''}
                                inputMode="tel"
                                placeholder="0211234567"
                            />
                            <InputError message={errors.phone} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="email">
                                Email{' '}
                                <span className="text-muted-foreground">
                                    (opsional)
                                </span>
                            </Label>
                            <Input
                                id="email"
                                name="email"
                                type="email"
                                defaultValue={branch?.email ?? ''}
                                placeholder="cabang@etoko.test"
                            />
                            <InputError message={errors.email} />
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
                                defaultValue={branch?.description ?? ''}
                                rows={3}
                                placeholder="Catatan singkat tentang cabang ini."
                            />
                            <InputError message={errors.description} />
                        </div>
                    </section>

                    <section className="grid gap-6">
                        <div>
                            <h3 className="text-sm font-medium">Alamat</h3>
                            <p className="text-xs text-muted-foreground">
                                Dipakai untuk menghitung ongkir dan menampilkan
                                lokasi cabang.
                            </p>
                        </div>

                        <div className="grid gap-6 md:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="province_name">Provinsi</Label>
                                <Input
                                    id="province_name"
                                    name="province_name"
                                    defaultValue={branch?.province_name ?? ''}
                                    required
                                    placeholder="DKI Jakarta"
                                />
                                <InputError message={errors.province_name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="city_name">
                                    Kota / Kabupaten
                                </Label>
                                <Input
                                    id="city_name"
                                    name="city_name"
                                    defaultValue={branch?.city_name ?? ''}
                                    required
                                    placeholder="Jakarta Pusat"
                                />
                                <InputError message={errors.city_name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="district_name">
                                    Kecamatan{' '}
                                    <span className="text-muted-foreground">
                                        (opsional)
                                    </span>
                                </Label>
                                <Input
                                    id="district_name"
                                    name="district_name"
                                    defaultValue={branch?.district_name ?? ''}
                                    placeholder="Menteng"
                                />
                                <InputError message={errors.district_name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="postal_code">
                                    Kode pos{' '}
                                    <span className="text-muted-foreground">
                                        (opsional)
                                    </span>
                                </Label>
                                <Input
                                    id="postal_code"
                                    name="postal_code"
                                    defaultValue={branch?.postal_code ?? ''}
                                    inputMode="numeric"
                                    maxLength={10}
                                    placeholder="10310"
                                />
                                <InputError message={errors.postal_code} />
                            </div>

                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="address_line">
                                    Alamat lengkap
                                </Label>
                                <Textarea
                                    id="address_line"
                                    name="address_line"
                                    defaultValue={branch?.address_line ?? ''}
                                    required
                                    rows={2}
                                    placeholder="Jl. M.H. Thamrin No. 1"
                                />
                                <InputError message={errors.address_line} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="latitude">
                                    Latitude{' '}
                                    <span className="text-muted-foreground">
                                        (opsional)
                                    </span>
                                </Label>
                                <Input
                                    id="latitude"
                                    name="latitude"
                                    defaultValue={branch?.latitude ?? ''}
                                    inputMode="decimal"
                                    placeholder="-6.1944000"
                                />
                                <InputError message={errors.latitude} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="longitude">
                                    Longitude{' '}
                                    <span className="text-muted-foreground">
                                        (opsional)
                                    </span>
                                </Label>
                                <Input
                                    id="longitude"
                                    name="longitude"
                                    defaultValue={branch?.longitude ?? ''}
                                    inputMode="decimal"
                                    placeholder="106.8229000"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Koordinat dipakai untuk menawarkan cabang
                                    terdekat ke pembeli.
                                </p>
                                <InputError message={errors.longitude} />
                            </div>
                        </div>
                    </section>

                    <section className="grid gap-4">
                        <div>
                            <h3 className="text-sm font-medium">
                                Cara pemenuhan
                            </h3>
                            <p className="text-xs text-muted-foreground">
                                Cabang harus mendukung minimal satu cara.
                            </p>
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <CheckboxField
                                name="supports_delivery"
                                label="Pengiriman"
                                description="Pesanan dikirim dari cabang ini ke alamat pembeli."
                                checked={supportsDelivery}
                                onChange={setSupportsDelivery}
                                error={errors.supports_delivery}
                            />
                            <CheckboxField
                                name="supports_pickup"
                                label="Ambil di cabang"
                                description="Pembeli mengambil pesanan sendiri di cabang ini."
                                checked={supportsPickup}
                                onChange={setSupportsPickup}
                                error={errors.supports_pickup}
                            />
                        </div>
                    </section>

                    <section className="grid gap-6 md:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="sort_order">Urutan tampil</Label>
                            <Input
                                id="sort_order"
                                name="sort_order"
                                type="number"
                                min={0}
                                defaultValue={branch?.sort_order ?? 0}
                                className="max-w-32"
                            />
                            <p className="text-xs text-muted-foreground">
                                Angka kecil tampil lebih dulu di daftar cabang.
                            </p>
                            <InputError message={errors.sort_order} />
                        </div>

                        {isEditing && (
                            <CheckboxField
                                name="is_default"
                                label="Cabang utama"
                                description="Cabang yang dipakai storefront saat pembeli belum memilih cabang. Hanya satu cabang yang boleh menjadi cabang utama."
                                checked={isDefault}
                                onChange={setIsDefault}
                                error={errors.is_default}
                            />
                        )}
                    </section>

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
 * A Radix checkbox submits nothing when unchecked, so the value travels in a
 * hidden field alongside it.
 */
function CheckboxField({
    name,
    label,
    description,
    checked,
    onChange,
    error,
}: {
    name: string;
    label: string;
    description: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    error?: string;
}) {
    return (
        <div className="flex items-start gap-3 rounded-lg border p-4">
            <input type="hidden" name={name} value={checked ? '1' : '0'} />
            <Checkbox
                id={name}
                checked={checked}
                onCheckedChange={(next) => onChange(next === true)}
            />
            <div className="grid gap-1">
                <Label htmlFor={name}>{label}</Label>
                <p className="text-xs text-muted-foreground">{description}</p>
                <InputError message={error} />
            </div>
        </div>
    );
}
