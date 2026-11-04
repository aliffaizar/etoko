import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { Star, Trash2 } from 'lucide-react';
import { useState } from 'react';
import BranchController from '@/actions/App/Http/Controllers/Admin/BranchController';
import { BranchForm } from '@/components/admin/branch-form';
import { BranchOperatingHoursCard } from '@/components/admin/branch-operating-hours-card';
import { BranchStaffCard } from '@/components/admin/branch-staff-card';
import { BranchWarehousesCard } from '@/components/admin/branch-warehouses-card';
import Heading from '@/components/heading';
import { StatusBadge, type StatusTone } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatDateTime } from '@/lib/format';
import { edit, index as branchesIndex } from '@/routes/admin/branches';
import type {
    AssignableStaffOption,
    BranchAbilities,
    BranchDetail,
    BranchOperatingHour,
    BranchScreenOptions,
    BranchStaffMember,
    BranchStatus,
    BranchWarehouse,
} from '@/types';

type BranchesEditProps = {
    branch: BranchDetail;
    operatingHours: BranchOperatingHour[];
    warehouses: BranchWarehouse[];
    staff: BranchStaffMember[];
    assignableStaff: AssignableStaffOption[];
    options: BranchScreenOptions;
    can: BranchAbilities;
};

const STATUS_TONE: Record<BranchStatus, StatusTone> = {
    active: 'success',
    inactive: 'danger',
    maintenance: 'warning',
};

/**
 * Everything about one branch on a single screen: its details and address
 * (P1-E2-T3), its week (T4), its staff (T5) and its warehouses (T6). Each card
 * submits to its own endpoint, so saving the opening hours never risks the
 * branch's own fields.
 */
export default function BranchesEdit({
    branch,
    operatingHours,
    warehouses,
    staff,
    assignableStaff,
    options,
    can,
}: BranchesEditProps) {
    // The breadcrumb carries the branch's name, so it comes from the page's own
    // props rather than a static `BranchesEdit.layout` object.
    setLayoutProps({
        breadcrumbs: [
            { title: 'Cabang', href: branchesIndex() },
            { title: branch.name, href: edit(branch.id) },
        ],
    });

    return (
        <>
            <Head title={`Cabang ${branch.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={branch.name}
                        description={`${branch.code} · Dibuat ${formatDateTime(branch.created_at)}`}
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        {branch.is_default && (
                            <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                <Star
                                    aria-hidden
                                    className="size-3.5 fill-amber-400 text-amber-500"
                                />
                                Cabang utama
                            </span>
                        )}
                        <StatusBadge
                            label={branch.status_label}
                            tone={STATUS_TONE[branch.status]}
                        />
                    </div>
                </div>

                <Card className="max-w-4xl">
                    <CardHeader>
                        <CardTitle>Data cabang</CardTitle>
                        <CardDescription>
                            Alamat dan koordinat dipakai untuk menghitung ongkir
                            dan menawarkan cabang terdekat ke pembeli.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <BranchForm
                            action={BranchController.update.form(branch.id)}
                            options={options}
                            branch={branch}
                            submitLabel="Simpan Perubahan"
                            secondaryAction={
                                <Button variant="ghost" asChild>
                                    <Link href={BranchController.index()}>
                                        Kembali ke daftar
                                    </Link>
                                </Button>
                            }
                        />
                    </CardContent>
                </Card>

                <div className="grid max-w-4xl gap-6">
                    <BranchOperatingHoursCard
                        branchId={branch.id}
                        hours={operatingHours}
                        canUpdate={can.update}
                    />

                    <BranchWarehousesCard
                        branchId={branch.id}
                        warehouses={warehouses}
                        canManage={can.manage_warehouses}
                    />

                    <BranchStaffCard
                        branchId={branch.id}
                        staff={staff}
                        assignableStaff={assignableStaff}
                        roles={options.roles}
                        canAssign={can.assign_staff}
                        canCreate={can.create_staff}
                    />

                    {can.delete && <DeleteBranchCard branch={branch} />}
                </div>
            </div>
        </>
    );
}

function DeleteBranchCard({ branch }: { branch: BranchDetail }) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    return (
        <Card className="border-destructive/40">
            <CardHeader>
                <CardTitle>Hapus cabang</CardTitle>
                <CardDescription>
                    Cabang dihapus secara lunak: gudang dan riwayatnya tetap
                    tersimpan. Cabang yang masih memiliki staf tidak dapat
                    dihapus — pindahkan stafnya terlebih dahulu.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Button variant="destructive" onClick={() => setOpen(true)}>
                    <Trash2 aria-hidden />
                    Hapus {branch.name}
                </Button>

                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Hapus cabang?</DialogTitle>
                            <DialogDescription>
                                Cabang <strong>{branch.name}</strong> tidak akan
                                lagi muncul di storefront maupun panel. Tindakan
                                ini dicatat di audit log.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => setOpen(false)}
                                disabled={processing}
                            >
                                Batal
                            </Button>
                            <Button
                                variant="destructive"
                                disabled={processing}
                                onClick={() =>
                                    router.delete(
                                        BranchController.destroy(branch.id).url,
                                        {
                                            onStart: () => setProcessing(true),
                                            onFinish: () =>
                                                setProcessing(false),
                                        },
                                    )
                                }
                            >
                                Hapus cabang
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </CardContent>
        </Card>
    );
}
