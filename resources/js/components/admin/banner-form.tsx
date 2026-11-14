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
import type {
    BannerDetail,
    BannerFormOptions,
    BannerPositionValue,
} from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

type BannerFormProps = {
    /** Wayfinder form props, e.g. `BannerController.store.form()`. */
    action: RouteFormDefinition<'post' | 'put'>;
    options: BannerFormOptions;
    /** Absent when creating. */
    banner?: BannerDetail;
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

/** The select's "every branch" choice. Radix selects cannot hold an empty value. */
const EVERY_BRANCH = 'all';

/**
 * Create and edit share this form (P2-E8-T1).
 *
 * The pictures are optional when editing and required when creating; the
 * current picture is shown so a banner is never replaced by accident.
 */
export function BannerForm({
    action,
    options,
    banner,
    submitLabel,
    secondaryAction,
}: BannerFormProps) {
    const [position, setPosition] = useState<BannerPositionValue>(
        banner?.position ?? 'hero',
    );
    const [branch, setBranch] = useState<string>(
        banner?.branch_id ? String(banner.branch_id) : EVERY_BRANCH,
    );
    const [isActive, setIsActive] = useState(banner?.is_active ?? true);

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
                            <Label htmlFor="title">Judul</Label>
                            <Input
                                id="title"
                                name="title"
                                defaultValue={banner?.title ?? ''}
                                required
                                maxLength={120}
                                placeholder="Diskon akhir pekan"
                            />
                            <InputError message={errors.title} />
                        </div>

                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="subtitle">
                                Keterangan{' '}
                                <span className="text-muted-foreground">
                                    (opsional)
                                </span>
                            </Label>
                            <Textarea
                                id="subtitle"
                                name="subtitle"
                                rows={2}
                                maxLength={255}
                                defaultValue={banner?.subtitle ?? ''}
                            />
                            <InputError message={errors.subtitle} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="link_url">
                                Tautan{' '}
                                <span className="text-muted-foreground">
                                    (opsional)
                                </span>
                            </Label>
                            <Input
                                id="link_url"
                                name="link_url"
                                type="url"
                                maxLength={500}
                                defaultValue={banner?.link_url ?? ''}
                                placeholder="https://"
                            />
                            <InputError message={errors.link_url} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="sort_order">Urutan</Label>
                            <Input
                                id="sort_order"
                                name="sort_order"
                                type="number"
                                min={0}
                                required
                                defaultValue={banner?.sort_order ?? 0}
                            />
                            <p className="text-xs text-muted-foreground">
                                Angka kecil tampil lebih dulu.
                            </p>
                            <InputError message={errors.sort_order} />
                        </div>

                        <div className="grid content-start gap-2">
                            <Label htmlFor="position">Posisi</Label>
                            <input
                                type="hidden"
                                name="position"
                                value={position}
                            />
                            <Select
                                value={position}
                                onValueChange={(next) =>
                                    setPosition(next as BannerPositionValue)
                                }
                            >
                                <SelectTrigger id="position">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        {options.positions.map((option) => (
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
                            <InputError message={errors.position} />
                        </div>

                        <div className="grid content-start gap-2">
                            <Label htmlFor="branch_id">Cabang</Label>
                            <input
                                type="hidden"
                                name="branch_id"
                                value={branch === EVERY_BRANCH ? '' : branch}
                            />
                            <Select value={branch} onValueChange={setBranch}>
                                <SelectTrigger id="branch_id">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        <SelectItem value={EVERY_BRANCH}>
                                            Semua cabang
                                        </SelectItem>
                                        {options.branches.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={String(option.value)}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                            <InputError message={errors.branch_id} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="starts_at">
                                Mulai tampil{' '}
                                <span className="text-muted-foreground">
                                    (opsional)
                                </span>
                            </Label>
                            <Input
                                id="starts_at"
                                name="starts_at"
                                type="datetime-local"
                                defaultValue={toLocalInput(banner?.starts_at)}
                            />
                            <InputError message={errors.starts_at} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="ends_at">
                                Berhenti tampil{' '}
                                <span className="text-muted-foreground">
                                    (opsional)
                                </span>
                            </Label>
                            <Input
                                id="ends_at"
                                name="ends_at"
                                type="datetime-local"
                                defaultValue={toLocalInput(banner?.ends_at)}
                            />
                            <InputError message={errors.ends_at} />
                        </div>
                    </section>

                    <section className="grid gap-6 md:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="image">
                                Gambar{' '}
                                <span className="text-muted-foreground">
                                    {banner?.image_url
                                        ? '(kosongkan untuk tetap)'
                                        : '(wajib)'}
                                </span>
                            </Label>
                            {banner?.image_url && (
                                <img
                                    src={banner.image_url}
                                    alt=""
                                    className="h-24 w-full max-w-xs rounded-md border object-cover"
                                />
                            )}
                            <input
                                id="image"
                                name="image"
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                required={!banner}
                                className="text-sm"
                            />
                            <p className="text-xs text-muted-foreground">
                                JPG, PNG, atau WebP, maks. 4 MB. Disarankan
                                1600×600.
                            </p>
                            <InputError message={errors.image} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="mobile_image">
                                Gambar seluler{' '}
                                <span className="text-muted-foreground">
                                    (opsional)
                                </span>
                            </Label>
                            {banner?.mobile_image_url && (
                                <img
                                    src={banner.mobile_image_url}
                                    alt=""
                                    className="h-24 w-full max-w-[10rem] rounded-md border object-cover"
                                />
                            )}
                            <input
                                id="mobile_image"
                                name="mobile_image"
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                className="text-sm"
                            />
                            <InputError message={errors.mobile_image} />
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
                                Tampilkan banner
                            </Label>
                            <p className="text-xs text-muted-foreground">
                                Banner nonaktif tidak tampil, walau periodenya
                                berlaku.
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
