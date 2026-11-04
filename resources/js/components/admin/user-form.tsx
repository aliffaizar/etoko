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
import type { StaffDetail, StaffFormOptions } from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

/**
 * The fields this form submits, so Inertia can type its validation errors.
 * Everything travels as a string because it is a plain HTML form.
 */
type UserFormData = {
    name: string;
    email: string;
    phone: string;
    role: string;
    branch_id: string;
    is_active: string;
    password: string;
    password_confirmation: string;
};

type UserFormProps = {
    /** Wayfinder form props, e.g. `UserController.store.form()`. */
    action: RouteFormDefinition<'post' | 'put'>;
    options: StaffFormOptions;
    /** Absent when creating. */
    user?: StaffDetail;
    submitLabel: string;
    /** Extra controls rendered next to the submit button. */
    secondaryAction?: ReactNode;
    /** Branch to select up front, e.g. when the branch screen linked here. */
    prefilledBranchId?: number | null;
};

/**
 * The create and edit forms differ only in their defaults and endpoint, so both
 * pages render this one component.
 *
 * The branch field appears only for roles that require one, and is locked to
 * the actor's own branch when they are branch-bound. Both rules are enforced
 * again server side by `ValidatesStaffAssignment`.
 */
export function UserForm({
    action,
    options,
    user,
    submitLabel,
    secondaryAction,
    prefilledBranchId,
}: UserFormProps) {
    const [role, setRole] = useState<string>(user?.role ?? '');
    const [isActive, setIsActive] = useState(user?.is_active ?? true);
    const [branchId, setBranchId] = useState<string>(
        user?.branch_id?.toString() ??
            options.lockedBranchId?.toString() ??
            prefilledBranchId?.toString() ??
            '',
    );

    const requiresBranch = useMemo(
        () =>
            options.roles.find((option) => option.value === role)
                ?.requires_branch ?? false,
        [options.roles, role],
    );

    const isBranchLocked = options.lockedBranchId !== null;

    return (
        <Form<UserFormData>
            {...action}
            className="flex flex-col gap-6"
            resetOnSuccess={false}
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-6 md:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Nama lengkap</Label>
                            <Input
                                id="name"
                                name="name"
                                defaultValue={user?.name ?? ''}
                                required
                                autoComplete="name"
                                placeholder="Nama staf"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                name="email"
                                type="email"
                                defaultValue={user?.email ?? ''}
                                required
                                autoComplete="off"
                                placeholder="nama@etoko.test"
                            />
                            <InputError message={errors.email} />
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
                                defaultValue={user?.phone ?? ''}
                                inputMode="tel"
                                autoComplete="tel"
                                placeholder="08xxxxxxxxxx"
                            />
                            <InputError message={errors.phone} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="role">Role</Label>
                            <input type="hidden" name="role" value={role} />
                            <Select
                                value={role}
                                onValueChange={(next) => {
                                    setRole(next);

                                    if (isBranchLocked) {
                                        setBranchId(
                                            options.lockedBranchId?.toString() ??
                                                '',
                                        );
                                    }
                                }}
                            >
                                <SelectTrigger id="role">
                                    <SelectValue placeholder="Pilih role" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        {options.roles.map((option) => (
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

                        {requiresBranch && (
                            <div className="grid gap-2">
                                <Label htmlFor="branch_id">Cabang</Label>
                                <input
                                    type="hidden"
                                    name="branch_id"
                                    value={branchId}
                                />
                                <Select
                                    value={branchId}
                                    onValueChange={setBranchId}
                                    disabled={isBranchLocked}
                                >
                                    <SelectTrigger id="branch_id">
                                        <SelectValue placeholder="Pilih cabang" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectGroup>
                                            {options.branches.map((option) => (
                                                <SelectItem
                                                    key={option.value}
                                                    value={option.value.toString()}
                                                >
                                                    {option.label}
                                                </SelectItem>
                                            ))}
                                        </SelectGroup>
                                    </SelectContent>
                                </Select>
                                <p className="text-xs text-muted-foreground">
                                    {isBranchLocked
                                        ? 'Anda hanya dapat menugaskan staf ke cabang Anda sendiri.'
                                        : 'Role ini wajib ditugaskan ke satu cabang.'}
                                </p>
                                <InputError message={errors.branch_id} />
                            </div>
                        )}

                        {!user && (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="password">Kata sandi</Label>
                                    <Input
                                        id="password"
                                        name="password"
                                        type="password"
                                        required
                                        autoComplete="new-password"
                                    />
                                    <InputError message={errors.password} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password_confirmation">
                                        Ulangi kata sandi
                                    </Label>
                                    <Input
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        type="password"
                                        required
                                        autoComplete="new-password"
                                    />
                                </div>
                            </>
                        )}
                    </div>

                    <div className="flex items-start gap-3 rounded-lg border p-4">
                        {/* A Radix checkbox submits nothing when unchecked, so
                            the value travels in a hidden field instead. */}
                        <input
                            type="hidden"
                            name="is_active"
                            value={isActive ? '1' : '0'}
                        />
                        <Checkbox
                            id="is_active"
                            checked={isActive}
                            onCheckedChange={(checked) =>
                                setIsActive(checked === true)
                            }
                        />
                        <div className="grid gap-1">
                            <Label htmlFor="is_active">Akun aktif</Label>
                            <p className="text-xs text-muted-foreground">
                                Akun yang tidak aktif tetap tersimpan, tetapi
                                tidak dapat masuk ke sistem.
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
