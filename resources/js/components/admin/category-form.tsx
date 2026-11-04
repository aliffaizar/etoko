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
import type { CategoryDetail, CategoryParentOption } from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

/**
 * The fields this form submits, so Inertia can type its validation errors.
 * Everything travels as a string because it is a plain HTML form.
 */
type CategoryFormData = {
    parent_id: string;
    name: string;
    slug: string;
    description: string;
    icon: string;
    is_active: string;
    sort_order: string;
};

/** Radix refuses an empty `SelectItem` value, so "no parent" carries a sentinel. */
const ROOT_VALUE = '__root__';

type CategoryFormProps = {
    /** Wayfinder form props, e.g. `CategoryController.store.form()`. */
    action: RouteFormDefinition<'post' | 'put'>;
    /** Categories this one may sit under; already filtered by the server. */
    parentOptions: CategoryParentOption[];
    /** Absent when creating. */
    category?: CategoryDetail;
    submitLabel: string;
    secondaryAction?: ReactNode;
};

/**
 * Create and edit differ only in their defaults and endpoint, so both pages
 * render this one component.
 *
 * The slug field appears only when editing: `CreateCategory` derives it from
 * the name, the same way a branch's slug is derived.
 *
 * `parentOptions` never contains a category that would break the three-level
 * limit or form a cycle — the server works that out — so the select can be
 * rendered as-is.
 */
export function CategoryForm({
    action,
    parentOptions,
    category,
    submitLabel,
    secondaryAction,
}: CategoryFormProps) {
    const isEditing = category !== undefined;
    const [parentId, setParentId] = useState<string>(
        category?.parent_id ? String(category.parent_id) : ROOT_VALUE,
    );
    const [isActive, setIsActive] = useState(category?.is_active ?? true);

    return (
        <Form<CategoryFormData>
            {...action}
            className="flex flex-col gap-8"
            resetOnSuccess={false}
        >
            {({ processing, errors }) => (
                <>
                    <section className="grid gap-6 md:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Nama kategori</Label>
                            <Input
                                id="name"
                                name="name"
                                defaultValue={category?.name ?? ''}
                                required
                                maxLength={255}
                                placeholder="Elektronik"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="parent_id">
                                Kategori induk{' '}
                                <span className="text-muted-foreground">
                                    (opsional)
                                </span>
                            </Label>
                            <input
                                type="hidden"
                                name="parent_id"
                                value={parentId === ROOT_VALUE ? '' : parentId}
                            />
                            <Select
                                value={parentId}
                                onValueChange={setParentId}
                            >
                                <SelectTrigger id="parent_id">
                                    <SelectValue placeholder="Pilih kategori induk" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        <SelectItem value={ROOT_VALUE}>
                                            Tanpa induk (kategori utama)
                                        </SelectItem>
                                        {parentOptions.map((option) => (
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
                            <p className="text-xs text-muted-foreground">
                                Kategori hanya boleh bertingkat sampai 3 level.
                                Induk yang sudah penuh tidak ditampilkan di
                                sini.
                            </p>
                            <InputError message={errors.parent_id} />
                        </div>

                        {isEditing && (
                            <div className="grid gap-2">
                                <Label htmlFor="slug">Slug</Label>
                                <Input
                                    id="slug"
                                    name="slug"
                                    defaultValue={category.slug}
                                    required
                                    placeholder="elektronik"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Bagian alamat kategori ini di storefront.
                                    Mengubahnya mengubah tautan yang sudah
                                    tersebar.
                                </p>
                                <InputError message={errors.slug} />
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="icon">
                                Ikon{' '}
                                <span className="text-muted-foreground">
                                    (opsional)
                                </span>
                            </Label>
                            <Input
                                id="icon"
                                name="icon"
                                defaultValue={category?.icon ?? ''}
                                maxLength={60}
                                placeholder="smartphone"
                            />
                            <p className="text-xs text-muted-foreground">
                                Nama ikon Lucide yang dipakai di storefront.
                            </p>
                            <InputError message={errors.icon} />
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
                                defaultValue={category?.description ?? ''}
                                rows={3}
                                placeholder="Penjelasan singkat tentang kategori ini."
                            />
                            <InputError message={errors.description} />
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
                                defaultValue={category?.sort_order ?? 0}
                                className="max-w-32"
                            />
                            <p className="text-xs text-muted-foreground">
                                Angka kecil tampil lebih dulu di antara kategori
                                setingkatnya.
                            </p>
                            <InputError message={errors.sort_order} />
                        </div>

                        <div className="flex items-start gap-3 rounded-lg border p-4">
                            {/* A Radix checkbox submits nothing when unchecked,
                                so the value travels in a hidden field. */}
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
                                    Kategori nonaktif tidak tampil di
                                    storefront. Menonaktifkan kategori ikut
                                    menonaktifkan seluruh subkategorinya.
                                </p>
                                <InputError message={errors.is_active} />
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
