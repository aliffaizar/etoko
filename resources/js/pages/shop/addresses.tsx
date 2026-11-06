import { Head, useForm } from '@inertiajs/react';
import { MapPin, Pencil, Plus, Star, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import account from '@/routes/shop/account';

type AddressData = {
    id: number;
    label: string;
    recipient_name: string;
    phone: string;
    province_name: string;
    city_name: string;
    district_name: string;
    postal_code: string;
    address_line: string;
    is_default: boolean;
    notes: string | null;
};

type AddressFormData = {
    label: string;
    recipient_name: string;
    phone: string;
    province_name: string;
    city_name: string;
    district_name: string;
    postal_code: string;
    address_line: string;
    is_default: boolean;
    notes: string;
    [key: string]: string | boolean;
};

type AddressesProps = {
    addresses: AddressData[];
};

const EMPTY_FORM: AddressFormData = {
    label: 'Rumah',
    recipient_name: '',
    phone: '',
    province_name: '',
    city_name: '',
    district_name: '',
    postal_code: '',
    address_line: '',
    is_default: false,
    notes: '',
};

export default function AddressesPage({ addresses }: AddressesProps) {
    const [editTarget, setEditTarget] = useState<AddressData | null>(null);
    const [creating, setCreating] = useState(false);
    const [deletingId, setDeletingId] = useState<number | null>(null);

    const isOpen = creating || editTarget !== null;

    const handleClose = () => {
        setCreating(false);
        setEditTarget(null);
    };

    return (
        <>
            <Head title="Alamat Saya" />

            <div className="mx-auto max-w-2xl px-4 py-8">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="flex items-center gap-2 text-2xl font-semibold">
                        <MapPin className="size-6" />
                        Alamat Saya
                    </h1>
                    <Button size="sm" onClick={() => setCreating(true)}>
                        <Plus className="size-4" />
                        Tambah Alamat
                    </Button>
                </div>

                {addresses.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-8 text-center text-muted-foreground">
                        <MapPin className="mx-auto mb-2 size-8" />
                        <p>Belum ada alamat tersimpan.</p>
                        <Button
                            variant="link"
                            className="mt-2"
                            onClick={() => setCreating(true)}
                        >
                            Tambah alamat pertama
                        </Button>
                    </div>
                ) : (
                    <div className="flex flex-col gap-3">
                        {addresses.map((address) => (
                            <AddressCard
                                key={address.id}
                                address={address}
                                onEdit={() => setEditTarget(address)}
                                onDelete={() => setDeletingId(address.id)}
                            />
                        ))}
                    </div>
                )}
            </div>

            {/* Create / Edit dialog */}
            <AddressDialog
                open={isOpen}
                onClose={handleClose}
                address={editTarget}
            />

            {/* Delete confirm dialog */}
            <DeleteDialog
                open={deletingId !== null}
                addressId={deletingId}
                onClose={() => setDeletingId(null)}
            />
        </>
    );
}

function AddressCard({
    address,
    onEdit,
    onDelete,
}: {
    address: AddressData;
    onEdit: () => void;
    onDelete: () => void;
}) {
    return (
        <div className="rounded-lg border p-4">
            <div className="mb-2 flex items-center gap-2">
                <span className="rounded bg-muted px-2 py-0.5 text-xs font-medium">{address.label}</span>
                {address.is_default && (
                    <span className="flex items-center gap-1 text-xs text-primary">
                        <Star className="size-3 fill-primary" />
                        Utama
                    </span>
                )}
                <div className="ml-auto flex gap-2">
                    <button
                        onClick={onEdit}
                        className="text-muted-foreground hover:text-foreground"
                        aria-label="Edit alamat"
                    >
                        <Pencil className="size-4" />
                    </button>
                    <button
                        onClick={onDelete}
                        className="text-muted-foreground hover:text-destructive"
                        aria-label="Hapus alamat"
                    >
                        <Trash2 className="size-4" />
                    </button>
                </div>
            </div>

            <p className="font-medium">{address.recipient_name}</p>
            <p className="text-sm text-muted-foreground">{address.phone}</p>
            <p className="mt-1 text-sm">
                {address.address_line}, {address.district_name}, {address.city_name},{' '}
                {address.province_name} {address.postal_code}
            </p>
            {address.notes && (
                <p className="mt-1 text-xs text-muted-foreground">Catatan: {address.notes}</p>
            )}
        </div>
    );
}

function AddressDialog({
    open,
    onClose,
    address,
}: {
    open: boolean;
    onClose: () => void;
    address: AddressData | null;
}) {
    const isEdit = address !== null;

    const { data, setData, post, patch, processing, errors, reset } = useForm<AddressFormData>(
        address
            ? {
                label: address.label,
                recipient_name: address.recipient_name,
                phone: address.phone,
                province_name: address.province_name,
                city_name: address.city_name,
                district_name: address.district_name,
                postal_code: address.postal_code,
                address_line: address.address_line,
                is_default: address.is_default,
                notes: address.notes ?? '',
            }
            : EMPTY_FORM,
    );

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        if (isEdit && address) {
            patch(account.addresses.update(address.id).url, {
                onSuccess: () => {
                    reset();
                    onClose();
                },
            });
        } else {
            post(account.addresses.store().url, {
                onSuccess: () => {
                    reset();
                    onClose();
                },
            });
        }
    };

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'Edit Alamat' : 'Tambah Alamat Baru'}</DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="col-span-2">
                            <Label htmlFor="label">Label</Label>
                            <Input
                                id="label"
                                value={data.label}
                                onChange={(e) => setData('label', e.target.value)}
                                placeholder="Rumah, Kantor, …"
                            />
                            {errors.label && <p className="mt-1 text-xs text-destructive">{errors.label}</p>}
                        </div>

                        <div>
                            <Label htmlFor="recipient_name">Nama Penerima</Label>
                            <Input
                                id="recipient_name"
                                value={data.recipient_name}
                                onChange={(e) => setData('recipient_name', e.target.value)}
                            />
                            {errors.recipient_name && <p className="mt-1 text-xs text-destructive">{errors.recipient_name}</p>}
                        </div>

                        <div>
                            <Label htmlFor="phone">Nomor Telepon</Label>
                            <Input
                                id="phone"
                                value={data.phone}
                                onChange={(e) => setData('phone', e.target.value)}
                            />
                            {errors.phone && <p className="mt-1 text-xs text-destructive">{errors.phone}</p>}
                        </div>

                        <div>
                            <Label htmlFor="province_name">Provinsi</Label>
                            <Input
                                id="province_name"
                                value={data.province_name}
                                onChange={(e) => setData('province_name', e.target.value)}
                            />
                            {errors.province_name && <p className="mt-1 text-xs text-destructive">{errors.province_name}</p>}
                        </div>

                        <div>
                            <Label htmlFor="city_name">Kota / Kabupaten</Label>
                            <Input
                                id="city_name"
                                value={data.city_name}
                                onChange={(e) => setData('city_name', e.target.value)}
                            />
                            {errors.city_name && <p className="mt-1 text-xs text-destructive">{errors.city_name}</p>}
                        </div>

                        <div>
                            <Label htmlFor="district_name">Kecamatan</Label>
                            <Input
                                id="district_name"
                                value={data.district_name}
                                onChange={(e) => setData('district_name', e.target.value)}
                            />
                            {errors.district_name && <p className="mt-1 text-xs text-destructive">{errors.district_name}</p>}
                        </div>

                        <div>
                            <Label htmlFor="postal_code">Kode Pos</Label>
                            <Input
                                id="postal_code"
                                value={data.postal_code}
                                onChange={(e) => setData('postal_code', e.target.value)}
                                maxLength={10}
                            />
                            {errors.postal_code && <p className="mt-1 text-xs text-destructive">{errors.postal_code}</p>}
                        </div>

                        <div className="col-span-2">
                            <Label htmlFor="address_line">Alamat Lengkap</Label>
                            <Textarea
                                id="address_line"
                                value={data.address_line}
                                onChange={(e) => setData('address_line', e.target.value)}
                                rows={3}
                            />
                            {errors.address_line && <p className="mt-1 text-xs text-destructive">{errors.address_line}</p>}
                        </div>

                        <div className="col-span-2">
                            <Label htmlFor="notes">Catatan (opsional)</Label>
                            <Input
                                id="notes"
                                value={data.notes}
                                onChange={(e) => setData('notes', e.target.value)}
                                placeholder="Patokan, lantai, dsb."
                            />
                        </div>

                        <div className="col-span-2 flex items-center gap-2">
                            <input
                                type="checkbox"
                                id="is_default"
                                checked={data.is_default}
                                onChange={(e) => setData('is_default', e.target.checked)}
                                className="size-4"
                            />
                            <Label htmlFor="is_default" className="cursor-pointer font-normal">
                                Jadikan alamat utama
                            </Label>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Batal
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Menyimpan…' : 'Simpan'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function DeleteDialog({
    open,
    addressId,
    onClose,
}: {
    open: boolean;
    addressId: number | null;
    onClose: () => void;
}) {
    const { delete: destroy, processing } = useForm();

    const submit = () => {
        if (addressId === null) {
            return;
        }

        destroy(account.addresses.destroy(addressId).url, {
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>Hapus Alamat?</DialogTitle>
                </DialogHeader>
                <p className="text-sm text-muted-foreground">
                    Alamat ini akan dihapus permanen dan tidak dapat dikembalikan.
                </p>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                    <Button variant="destructive" onClick={submit} disabled={processing}>
                        Hapus
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
