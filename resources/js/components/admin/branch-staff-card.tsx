import { Form, Link } from '@inertiajs/react';
import { Plus, UserPlus, Users } from 'lucide-react';
import { useState } from 'react';
import BranchStaffController from '@/actions/App/Http/Controllers/Admin/BranchStaffController';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatRelative } from '@/lib/format';
import type { AssignableStaffOption, BranchStaffMember } from '@/types';

type BranchStaffCardProps = {
    branchId: number;
    staff: BranchStaffMember[];
    assignableStaff: AssignableStaffOption[];
    roles: Array<{ value: string; label: string }>;
    canAssign: boolean;
    canCreate: boolean;
};

/**
 * The accounts stationed at this branch (P1-E2-T5).
 *
 * Two ways in: create a brand new account, which hands over to `/admin/users`
 * with this branch pre-selected, or move an existing account of another branch
 * here. Editing an account itself stays on the user screen, so there is exactly
 * one form for a staff account in the application.
 */
export function BranchStaffCard({
    branchId,
    staff,
    assignableStaff,
    roles,
    canAssign,
    canCreate,
}: BranchStaffCardProps) {
    const [isAssigning, setIsAssigning] = useState(false);

    return (
        <Card>
            <CardHeader>
                <CardTitle>Staf cabang</CardTitle>
                <CardDescription>
                    Admin cabang, staff gudang, dan kasir yang bertugas di
                    cabang ini.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {staff.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 rounded-lg border border-dashed p-8 text-center">
                        <Users
                            aria-hidden
                            className="size-6 text-muted-foreground"
                        />
                        <p className="text-sm font-medium">
                            Belum ada staf di cabang ini
                        </p>
                        <p className="text-xs text-muted-foreground">
                            Tambahkan admin cabang agar cabang ini bisa
                            dioperasikan.
                        </p>
                    </div>
                ) : (
                    <ul className="flex flex-col divide-y rounded-lg border">
                        {staff.map((member) => (
                            <li
                                key={member.id}
                                className="flex flex-col gap-2 p-3 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div className="min-w-0">
                                    <p className="truncate font-medium">
                                        {member.name}
                                    </p>
                                    <p className="truncate text-xs text-muted-foreground">
                                        {member.email} · Login terakhir{' '}
                                        {member.last_login_at
                                            ? formatRelative(
                                                  member.last_login_at,
                                              )
                                            : 'belum pernah'}
                                    </p>
                                </div>

                                <div className="flex shrink-0 flex-wrap items-center gap-2">
                                    <StatusBadge
                                        label={
                                            member.role_label ?? 'Tanpa role'
                                        }
                                        tone={
                                            member.role_label
                                                ? 'neutral'
                                                : 'warning'
                                        }
                                    />
                                    <StatusBadge
                                        label={
                                            member.is_active
                                                ? 'Aktif'
                                                : 'Nonaktif'
                                        }
                                        tone={
                                            member.is_active
                                                ? 'success'
                                                : 'danger'
                                        }
                                    />
                                    {member.can_update && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={UserController.edit(
                                                    member.id,
                                                )}
                                            >
                                                Ubah
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                {(canCreate || canAssign) && (
                    <div className="flex flex-wrap items-center gap-3">
                        {canCreate && (
                            <Button variant="secondary" asChild>
                                <Link
                                    href={
                                        UserController.create({
                                            query: { branch_id: branchId },
                                        }).url
                                    }
                                >
                                    <Plus aria-hidden />
                                    Tambah Staf Baru
                                </Link>
                            </Button>
                        )}

                        {canAssign && (
                            <Button
                                variant="ghost"
                                onClick={() => setIsAssigning(true)}
                                disabled={assignableStaff.length === 0}
                            >
                                <UserPlus aria-hidden />
                                Pindahkan Staf Ke Sini
                            </Button>
                        )}
                    </div>
                )}

                {canAssign && assignableStaff.length === 0 && (
                    <p className="text-xs text-muted-foreground">
                        Tidak ada staf cabang lain yang bisa dipindahkan ke
                        sini.
                    </p>
                )}
            </CardContent>

            <AssignStaffDialog
                branchId={branchId}
                open={isAssigning}
                onClose={() => setIsAssigning(false)}
                assignableStaff={assignableStaff}
                roles={roles}
            />
        </Card>
    );
}

type AssignStaffFormData = {
    user_id: string;
    role: string;
};

function AssignStaffDialog({
    branchId,
    open,
    onClose,
    assignableStaff,
    roles,
}: {
    branchId: number;
    open: boolean;
    onClose: () => void;
    assignableStaff: AssignableStaffOption[];
    roles: Array<{ value: string; label: string }>;
}) {
    const [userId, setUserId] = useState('');
    const [role, setRole] = useState('');

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent>
                <Form<AssignStaffFormData>
                    {...BranchStaffController.store.form(branchId)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => {
                        setUserId('');
                        setRole('');
                        onClose();
                    }}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    Pindahkan staf ke cabang ini
                                </DialogTitle>
                                <DialogDescription>
                                    Akun yang dipilih akan berpindah dari
                                    cabangnya sekarang ke cabang ini, dengan
                                    role yang Anda tentukan.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="grid gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="assign_user_id">
                                        Pengguna
                                    </Label>
                                    <input
                                        type="hidden"
                                        name="user_id"
                                        value={userId}
                                    />
                                    <Select
                                        value={userId}
                                        onValueChange={setUserId}
                                    >
                                        <SelectTrigger id="assign_user_id">
                                            <SelectValue placeholder="Pilih pengguna" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectGroup>
                                                {assignableStaff.map(
                                                    (option) => (
                                                        <SelectItem
                                                            key={option.value}
                                                            value={option.value.toString()}
                                                        >
                                                            {option.label}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectGroup>
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.user_id} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="assign_role">
                                        Role di cabang ini
                                    </Label>
                                    <input
                                        type="hidden"
                                        name="role"
                                        value={role}
                                    />
                                    <Select
                                        value={role}
                                        onValueChange={setRole}
                                    >
                                        <SelectTrigger id="assign_role">
                                            <SelectValue placeholder="Pilih role" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectGroup>
                                                {roles.map((option) => (
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
                                    <InputError message={errors.role} />
                                </div>
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
                                    Pindahkan Staf
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
