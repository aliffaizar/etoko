import { Form, router } from '@inertiajs/react';
import { Plus, Star, Warehouse as WarehouseIcon } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import BranchWarehouseController from '@/actions/App/Http/Controllers/Admin/BranchWarehouseController';
import InputError from '@/components/input-error';
import { StatusBadge } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { BranchWarehouse } from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

type BranchWarehousesCardProps = {
    branchId: number;
    warehouses: BranchWarehouse[];
    canManage: boolean;
};

/**
 * The branch's warehouses (P1-E2-T6).
 *
 * Stock is held per warehouse, and exactly one warehouse per branch is the
 * default that stock moves through unless a document names another. The default
 * can be handed over but not simply dropped, so it carries no delete or
 * deactivate control of its own.
 */
export function BranchWarehousesCard({
    branchId,
    warehouses,
    canManage,
}: BranchWarehousesCardProps) {
    const [editing, setEditing] = useState<BranchWarehouse | null>(null);
    const [isAdding, setIsAdding] = useState(false);
    const [pendingDelete, setPendingDelete] = useState<BranchWarehouse | null>(
        null,
    );

    return (
        <Card>
            <CardHeader>
                <CardTitle>Gudang</CardTitle>
                <CardDescription>
                    Stok disimpan per gudang. Gudang utama dipakai sebagai
                    tujuan dan sumber default untuk barang masuk dan keluar.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {warehouses.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 rounded-lg border border-dashed p-8 text-center">
                        <WarehouseIcon
                            aria-hidden
                            className="size-6 text-muted-foreground"
                        />
                        <p className="text-sm font-medium">Belum ada gudang</p>
                        <p className="text-xs text-muted-foreground">
                            Cabang membutuhkan minimal satu gudang untuk
                            menyimpan stok.
                        </p>
                    </div>
                ) : (
                    <ul className="flex flex-col divide-y rounded-lg border">
                        {warehouses.map((warehouse) => (
                            <li
                                key={warehouse.id}
                                className="flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div className="min-w-0">
                                    <p className="flex flex-wrap items-center gap-2 font-medium">
                                        {warehouse.name}
                                        {warehouse.is_default && (
                                            <span className="inline-flex items-center gap-1 text-xs font-normal text-muted-foreground">
                                                <Star
                                                    aria-hidden
                                                    className="size-3.5 fill-amber-400 text-amber-500"
                                                />
                                                Gudang utama
                                            </span>
                                        )}
                                        {!warehouse.is_active && (
                                            <StatusBadge
                                                label="Nonaktif"
                                                tone="danger"
                                            />
                                        )}
                                    </p>
                                    <p className="truncate text-xs text-muted-foreground">
                                        {warehouse.code}
                                        {` · ${warehouse.on_hand} unit stok`}
                                        {warehouse.address
                                            ? ` · ${warehouse.address}`
                                            : ''}
                                    </p>
                                    {canManage &&
                                        warehouse.delete_blocked_reason !==
                                            null && (
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {
                                                    warehouse.delete_blocked_reason
                                                }
                                            </p>
                                        )}
                                </div>

                                {canManage && (
                                    <div className="flex shrink-0 flex-wrap items-center gap-2">
                                        {!warehouse.is_default && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    router.patch(
                                                        BranchWarehouseController.setDefault(
                                                            [
                                                                branchId,
                                                                warehouse.id,
                                                            ],
                                                        ).url,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Jadikan utama
                                            </Button>
                                        )}
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                setEditing(warehouse)
                                            }
                                        >
                                            Ubah
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="text-destructive hover:text-destructive"
                                            disabled={!warehouse.can_delete}
                                            title={
                                                warehouse.delete_blocked_reason ??
                                                undefined
                                            }
                                            onClick={() =>
                                                setPendingDelete(warehouse)
                                            }
                                        >
                                            Hapus
                                        </Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                {canManage && (
                    <div>
                        <Button
                            variant="secondary"
                            onClick={() => setIsAdding(true)}
                        >
                            <Plus aria-hidden />
                            Tambah Gudang
                        </Button>
                    </div>
                )}
            </CardContent>

            <WarehouseDialog
                open={isAdding}
                onClose={() => setIsAdding(false)}
                title="Tambah gudang"
                description="Gudang pertama di cabang ini otomatis menjadi gudang utama."
                submitLabel="Simpan Gudang"
                action={BranchWarehouseController.store.form(branchId)}
            />

            <WarehouseDialog
                // Remount for another warehouse, so the dialog's defaults are
                // the ones of the row that was clicked.
                key={editing?.id ?? 'edit'}
                open={editing !== null}
                onClose={() => setEditing(null)}
                title="Ubah gudang"
                description="Kode gudang hanya perlu unik di dalam cabang ini."
                submitLabel="Simpan Perubahan"
                warehouse={editing ?? undefined}
                action={
                    editing
                        ? BranchWarehouseController.update.form([
                              branchId,
                              editing.id,
                          ])
                        : undefined
                }
            />

            <DeleteWarehouseDialog
                branchId={branchId}
                warehouse={pendingDelete}
                onClose={() => setPendingDelete(null)}
            />
        </Card>
    );
}

type WarehouseFormData = {
    code: string;
    name: string;
    address: string;
    is_default: string;
    is_active: string;
};

function WarehouseDialog({
    open,
    onClose,
    title,
    description,
    submitLabel,
    warehouse,
    action,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    description: string;
    submitLabel: string;
    warehouse?: BranchWarehouse;
    action?: RouteFormDefinition<'post'>;
}) {
    const isDefault = warehouse?.is_default ?? false;
    const [isActive, setIsActive] = useState(warehouse?.is_active ?? true);

    if (!action) {
        return null;
    }

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent>
                <Form<WarehouseFormData>
                    {...action}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>{title}</DialogTitle>
                                <DialogDescription>
                                    {description}
                                </DialogDescription>
                            </DialogHeader>

                            {/* The default flag is handed over with its own
                                control, so it only travels along unchanged. */}
                            <input
                                type="hidden"
                                name="is_default"
                                value={isDefault ? '1' : '0'}
                            />

                            <div className="grid gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="warehouse_code">
                                        Kode gudang
                                    </Label>
                                    <Input
                                        id="warehouse_code"
                                        name="code"
                                        defaultValue={warehouse?.code ?? ''}
                                        required
                                        maxLength={30}
                                        placeholder="WH-01"
                                        className="uppercase"
                                    />
                                    <InputError message={errors.code} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="warehouse_name">
                                        Nama gudang
                                    </Label>
                                    <Input
                                        id="warehouse_name"
                                        name="name"
                                        defaultValue={warehouse?.name ?? ''}
                                        required
                                        placeholder="Gudang Utama"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="warehouse_address">
                                        Alamat{' '}
                                        <span className="text-muted-foreground">
                                            (opsional)
                                        </span>
                                    </Label>
                                    <Textarea
                                        id="warehouse_address"
                                        name="address"
                                        defaultValue={warehouse?.address ?? ''}
                                        rows={2}
                                    />
                                    <InputError message={errors.address} />
                                </div>

                                <div className="flex items-start gap-3 rounded-lg border p-3">
                                    <input
                                        type="hidden"
                                        name="is_active"
                                        value={
                                            isDefault || isActive ? '1' : '0'
                                        }
                                    />
                                    <Checkbox
                                        id="warehouse_is_active"
                                        checked={isDefault || isActive}
                                        disabled={isDefault}
                                        onCheckedChange={(checked) =>
                                            setIsActive(checked === true)
                                        }
                                    />
                                    <div className="grid gap-1">
                                        <Label htmlFor="warehouse_is_active">
                                            Gudang aktif
                                        </Label>
                                        <p className="text-xs text-muted-foreground">
                                            {isDefault
                                                ? 'Gudang utama selalu aktif. Tunjuk gudang lain sebagai utama untuk menonaktifkan yang ini.'
                                                : 'Gudang nonaktif tidak bisa dipilih di dokumen stok baru.'}
                                        </p>
                                        <InputError
                                            message={errors.is_active}
                                        />
                                    </div>
                                </div>

                                <InputError message={errors.is_default} />
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={onClose}
                                    disabled={processing}
                                >
                                    Batal
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {submitLabel}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function DeleteWarehouseDialog({
    branchId,
    warehouse,
    onClose,
}: {
    branchId: number;
    warehouse: BranchWarehouse | null;
    onClose: () => void;
}): ReactNode {
    const [processing, setProcessing] = useState(false);

    return (
        <Dialog
            open={warehouse !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Hapus gudang?</DialogTitle>
                    <DialogDescription>
                        Gudang <strong>{warehouse?.name}</strong> akan dihapus
                        dari cabang ini. Gudang utama tidak dapat dihapus.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={onClose}
                        disabled={processing}
                    >
                        Batal
                    </Button>
                    <Button
                        variant="destructive"
                        disabled={processing}
                        onClick={() => {
                            if (!warehouse) {
                                return;
                            }

                            router.delete(
                                BranchWarehouseController.destroy([
                                    branchId,
                                    warehouse.id,
                                ]).url,
                                {
                                    preserveScroll: true,
                                    onStart: () => setProcessing(true),
                                    onFinish: () => {
                                        setProcessing(false);
                                        onClose();
                                    },
                                },
                            );
                        }}
                    >
                        Hapus gudang
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
