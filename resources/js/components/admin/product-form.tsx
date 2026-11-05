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
import { formatRupiah } from '@/lib/format';
import type { ProductDetail, ProductFormOptions } from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

/** The publication choices the form offers, mirroring the status badge. */
type PublicationMode = 'draft' | 'published' | 'scheduled';

const PUBLICATION_CHOICES: {
    value: PublicationMode;
    label: string;
    description: string;
}[] = [
    {
        value: 'draft',
        label: 'Draf',
        description: 'Disimpan, belum tampil ke pembeli.',
    },
    {
        value: 'published',
        label: 'Terbitkan',
        description: 'Langsung tampil ke pembeli.',
    },
    {
        value: 'scheduled',
        label: 'Terjadwal',
        description: 'Tampil otomatis pada waktu yang dipilih.',
    },
];

/**
 * The fields this form submits, so Inertia can type its validation errors.
 * Everything travels as a string because it is a plain HTML form.
 */
type ProductFormData = {
    category_id: string;
    name: string;
    slug: string;
    type: string;
    sku: string;
    barcode: string;
    short_description: string;
    description: string;
    base_price: string;
    compare_at_price: string;
    cost_price: string;
    weight_gram: string;
    length_cm: string;
    width_cm: string;
    height_cm: string;
    is_active: string;
    is_featured: string;
    publication_mode: string;
    published_at: string;
    meta_title: string;
    meta_description: string;
};

type ProductFormProps = {
    /** Wayfinder form props, e.g. `ProductController.store.form()`. */
    action: RouteFormDefinition<'post' | 'put'>;
    options: ProductFormOptions;
    /** Absent when creating. */
    product?: ProductDetail;
    submitLabel: string;
    secondaryAction?: ReactNode;
};

/**
 * Create and edit differ only in their defaults and endpoint, so both pages
 * render this one component.
 *
 * The slug appears only when editing: `CreateProduct` derives it from the
 * name, as it does for a branch and a category.
 *
 * The SKU and barcode fields belong to the product's default variant, which
 * every product owns (ADR-020). Per-variant SKUs arrive with the variant
 * editor (P1-E3-T6), and the photo gallery with P1-E3-T7.
 *
 * Prices are whole rupiah (ADR-008): the inputs accept digits only and show a
 * formatted preview underneath, so nobody has to guess whether a thousands
 * separator is expected.
 */
export function ProductForm({
    action,
    options,
    product,
    submitLabel,
    secondaryAction,
}: ProductFormProps) {
    const isEditing = product !== undefined;
    const [categoryId, setCategoryId] = useState<string>(
        product ? String(product.category_id) : '',
    );
    const [type, setType] = useState<string>(product?.type ?? 'simple');
    const [isActive, setIsActive] = useState(product?.is_active ?? true);
    // A new product starts as a draft on purpose — writing a catalogue and
    // releasing it are different moments — but the choice is now in front of
    // the user instead of hidden behind an empty date field.
    const [publicationMode, setPublicationMode] = useState<PublicationMode>(
        product?.publication_mode ?? 'draft',
    );
    const [isFeatured, setIsFeatured] = useState(product?.is_featured ?? false);
    const [basePrice, setBasePrice] = useState<string>(
        product ? String(product.base_price) : '',
    );

    return (
        <Form<ProductFormData>
            {...action}
            className="flex flex-col gap-8"
            resetOnSuccess={false}
        >
            {({ processing, errors }) => (
                <>
                    <section className="grid gap-6 md:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Nama produk</Label>
                            <Input
                                id="name"
                                name="name"
                                defaultValue={product?.name ?? ''}
                                required
                                maxLength={255}
                                placeholder="Kipas Angin Berdiri 16 Inci"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="category_id">Kategori</Label>
                            <input
                                type="hidden"
                                name="category_id"
                                value={categoryId}
                            />
                            <Select
                                value={categoryId}
                                onValueChange={setCategoryId}
                            >
                                <SelectTrigger id="category_id">
                                    <SelectValue placeholder="Pilih kategori" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        {options.categories.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={String(option.value)}
                                            >
                                                {'— '.repeat(option.depth - 1)}
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                            <InputError message={errors.category_id} />
                        </div>

                        {isEditing && (
                            <div className="grid gap-2">
                                <Label htmlFor="slug">Slug</Label>
                                <Input
                                    id="slug"
                                    name="slug"
                                    defaultValue={product.slug}
                                    required
                                    placeholder="kipas-angin-berdiri"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Bagian alamat produk ini di storefront.
                                    Mengubahnya mengubah tautan yang sudah
                                    tersebar.
                                </p>
                                <InputError message={errors.slug} />
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="type">Tipe produk</Label>
                            <input type="hidden" name="type" value={type} />
                            <Select value={type} onValueChange={setType}>
                                <SelectTrigger id="type">
                                    <SelectValue placeholder="Pilih tipe" />
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
                            <p className="text-xs text-muted-foreground">
                                Produk bervarian mendapat kombinasi atribut di
                                editor varian, yang menyusul di tahap
                                berikutnya.
                            </p>
                            <InputError message={errors.type} />
                        </div>

                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="short_description">
                                Deskripsi singkat{' '}
                                <span className="text-muted-foreground">
                                    (opsional)
                                </span>
                            </Label>
                            <Textarea
                                id="short_description"
                                name="short_description"
                                defaultValue={product?.short_description ?? ''}
                                rows={2}
                                maxLength={500}
                                placeholder="Satu atau dua baris yang tampil di kartu produk."
                            />
                            <InputError message={errors.short_description} />
                        </div>

                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="description">
                                Deskripsi lengkap{' '}
                                <span className="text-muted-foreground">
                                    (opsional)
                                </span>
                            </Label>
                            <Textarea
                                id="description"
                                name="description"
                                defaultValue={product?.description ?? ''}
                                rows={6}
                                placeholder="Penjelasan detail produk."
                            />
                            <InputError message={errors.description} />
                        </div>
                    </section>

                    <section className="grid gap-6">
                        <div>
                            <h3 className="text-sm font-medium">
                                Identitas & harga
                            </h3>
                            <p className="text-xs text-muted-foreground">
                                SKU dan barcode milik varian utama produk ini.
                                Harga di sini berlaku untuk semua cabang, sampai
                                sebuah cabang menetapkan harga khusus.
                            </p>
                        </div>

                        <div className="grid gap-6 md:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="sku">SKU</Label>
                                <Input
                                    id="sku"
                                    name="sku"
                                    defaultValue={product?.sku ?? ''}
                                    required
                                    maxLength={60}
                                    placeholder="KPS-16-PUTIH"
                                    className="uppercase"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Huruf kapital, angka, titik, tanda hubung,
                                    dan garis bawah.
                                </p>
                                <InputError message={errors.sku} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="barcode">
                                    Barcode{' '}
                                    <span className="text-muted-foreground">
                                        (opsional)
                                    </span>
                                </Label>
                                <Input
                                    id="barcode"
                                    name="barcode"
                                    defaultValue={product?.barcode ?? ''}
                                    maxLength={60}
                                    inputMode="numeric"
                                    placeholder="8991102000123"
                                />
                                <InputError message={errors.barcode} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="base_price">Harga jual</Label>
                                <Input
                                    id="base_price"
                                    name="base_price"
                                    type="number"
                                    min={0}
                                    step={1}
                                    required
                                    value={basePrice}
                                    onChange={(event) =>
                                        setBasePrice(event.target.value)
                                    }
                                    placeholder="250000"
                                />
                                <p className="text-xs text-muted-foreground">
                                    {basePrice === ''
                                        ? 'Rupiah bulat, tanpa titik atau koma.'
                                        : formatRupiah(Number(basePrice))}
                                </p>
                                <InputError message={errors.base_price} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="compare_at_price">
                                    Harga sebelum diskon{' '}
                                    <span className="text-muted-foreground">
                                        (opsional)
                                    </span>
                                </Label>
                                <Input
                                    id="compare_at_price"
                                    name="compare_at_price"
                                    type="number"
                                    min={0}
                                    step={1}
                                    defaultValue={
                                        product?.compare_at_price ?? ''
                                    }
                                    placeholder="300000"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Ditampilkan tercoret di storefront, jadi
                                    harus lebih tinggi dari harga jual.
                                </p>
                                <InputError message={errors.compare_at_price} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="cost_price">
                                    Harga pokok{' '}
                                    <span className="text-muted-foreground">
                                        (opsional)
                                    </span>
                                </Label>
                                <Input
                                    id="cost_price"
                                    name="cost_price"
                                    type="number"
                                    min={0}
                                    step={1}
                                    defaultValue={product?.cost_price ?? ''}
                                    placeholder="180000"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Hanya dipakai di laporan margin, tidak
                                    pernah tampil ke pembeli.
                                </p>
                                <InputError message={errors.cost_price} />
                            </div>
                        </div>
                    </section>

                    <section className="grid gap-6">
                        <div>
                            <h3 className="text-sm font-medium">
                                Berat & dimensi
                            </h3>
                            <p className="text-xs text-muted-foreground">
                                Dipakai untuk menghitung ongkos kirim.
                            </p>
                        </div>

                        <div className="grid gap-6 sm:grid-cols-2 md:grid-cols-4">
                            <div className="grid gap-2">
                                <Label htmlFor="weight_gram">
                                    Berat (gram)
                                </Label>
                                <Input
                                    id="weight_gram"
                                    name="weight_gram"
                                    type="number"
                                    min={0}
                                    step={1}
                                    required
                                    defaultValue={product?.weight_gram ?? 0}
                                    placeholder="1500"
                                />
                                <InputError message={errors.weight_gram} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="length_cm">Panjang (cm)</Label>
                                <Input
                                    id="length_cm"
                                    name="length_cm"
                                    type="number"
                                    min={0}
                                    step={1}
                                    defaultValue={product?.length_cm ?? ''}
                                />
                                <InputError message={errors.length_cm} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="width_cm">Lebar (cm)</Label>
                                <Input
                                    id="width_cm"
                                    name="width_cm"
                                    type="number"
                                    min={0}
                                    step={1}
                                    defaultValue={product?.width_cm ?? ''}
                                />
                                <InputError message={errors.width_cm} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="height_cm">Tinggi (cm)</Label>
                                <Input
                                    id="height_cm"
                                    name="height_cm"
                                    type="number"
                                    min={0}
                                    step={1}
                                    defaultValue={product?.height_cm ?? ''}
                                />
                                <InputError message={errors.height_cm} />
                            </div>
                        </div>
                    </section>

                    <section className="grid gap-6">
                        <div>
                            <h3 className="text-sm font-medium">
                                Publikasi & SEO
                            </h3>
                            <p className="text-xs text-muted-foreground">
                                Produk hanya terlihat pembeli bila aktif dan
                                tanggal terbitnya sudah lewat.
                            </p>
                        </div>

                        <div className="grid gap-6 md:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="publication_mode">
                                    Status terbit
                                </Label>
                                <input
                                    type="hidden"
                                    name="publication_mode"
                                    value={publicationMode}
                                />
                                <div
                                    id="publication_mode"
                                    role="radiogroup"
                                    aria-label="Status terbit"
                                    className="grid gap-2 sm:grid-cols-3"
                                >
                                    {PUBLICATION_CHOICES.map((choice) => (
                                        <button
                                            key={choice.value}
                                            type="button"
                                            role="radio"
                                            aria-checked={
                                                publicationMode === choice.value
                                            }
                                            onClick={() =>
                                                setPublicationMode(choice.value)
                                            }
                                            className={`rounded-lg border p-3 text-start transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none ${
                                                publicationMode === choice.value
                                                    ? 'border-primary bg-primary/5'
                                                    : 'hover:bg-muted'
                                            }`}
                                        >
                                            <span className="block text-sm font-medium">
                                                {choice.label}
                                            </span>
                                            <span className="mt-0.5 block text-xs text-muted-foreground">
                                                {choice.description}
                                            </span>
                                        </button>
                                    ))}
                                </div>

                                {publicationMode === 'scheduled' && (
                                    <div className="mt-1 grid gap-2">
                                        <Label htmlFor="published_at">
                                            Tanggal & jam terbit
                                        </Label>
                                        <Input
                                            id="published_at"
                                            name="published_at"
                                            type="datetime-local"
                                            required
                                            defaultValue={
                                                product?.published_at ?? ''
                                            }
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Produk tampil otomatis begitu waktu
                                            ini lewat.
                                        </p>
                                    </div>
                                )}
                                <InputError message={errors.published_at} />
                                <InputError message={errors.publication_mode} />
                            </div>

                            <div className="grid gap-3">
                                <CheckboxField
                                    name="is_active"
                                    label="Aktif"
                                    description="Produk nonaktif tidak pernah tampil ke pembeli, apa pun tanggal terbitnya."
                                    checked={isActive}
                                    onChange={setIsActive}
                                    error={errors.is_active}
                                />
                                <CheckboxField
                                    name="is_featured"
                                    label="Produk unggulan"
                                    description="Ditonjolkan di halaman depan storefront."
                                    checked={isFeatured}
                                    onChange={setIsFeatured}
                                    error={errors.is_featured}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="meta_title">
                                    Meta title{' '}
                                    <span className="text-muted-foreground">
                                        (opsional)
                                    </span>
                                </Label>
                                <Input
                                    id="meta_title"
                                    name="meta_title"
                                    defaultValue={product?.meta_title ?? ''}
                                    maxLength={255}
                                    placeholder="Kipas Angin Berdiri 16 Inci — Hemat Listrik"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Kosong berarti memakai nama produk.
                                </p>
                                <InputError message={errors.meta_title} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="meta_description">
                                    Meta description{' '}
                                    <span className="text-muted-foreground">
                                        (opsional)
                                    </span>
                                </Label>
                                <Textarea
                                    id="meta_description"
                                    name="meta_description"
                                    defaultValue={
                                        product?.meta_description ?? ''
                                    }
                                    rows={2}
                                    maxLength={255}
                                    placeholder="Ringkasan satu kalimat untuk hasil pencarian."
                                />
                                <InputError message={errors.meta_description} />
                            </div>
                        </div>
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
