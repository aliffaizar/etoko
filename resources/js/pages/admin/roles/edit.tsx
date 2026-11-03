import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import RoleController from '@/actions/App/Http/Controllers/Admin/RoleController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { formatNumber } from '@/lib/format';
import { edit, index as rolesIndex } from '@/routes/admin/roles';

type PermissionItem = {
    name: string;
    action: string;
};

type PermissionGroup = {
    key: string;
    label: string;
    permissions: PermissionItem[];
};

type RolesEditProps = {
    role: {
        id: number;
        name: string;
        label: string;
        permissions: string[];
    };
    groups: PermissionGroup[];
};

export default function RolesEdit({ role, groups }: RolesEditProps) {
    const [granted, setGranted] = useState<string[]>(role.permissions);

    setLayoutProps({
        breadcrumbs: [
            { title: 'Role & Permission', href: rolesIndex() },
            { title: role.label, href: edit(role.id) },
        ],
    });

    const total = useMemo(
        () =>
            groups.reduce(
                (count, group) => count + group.permissions.length,
                0,
            ),
        [groups],
    );

    const toggle = (permission: string, checked: boolean): void => {
        setGranted((current) =>
            checked
                ? [...current, permission]
                : current.filter((name) => name !== permission),
        );
    };

    const toggleGroup = (group: PermissionGroup, checked: boolean): void => {
        const names = group.permissions.map((permission) => permission.name);

        setGranted((current) =>
            checked
                ? [...new Set([...current, ...names])]
                : current.filter((name) => !names.includes(name)),
        );
    };

    return (
        <>
            <Head title={`Permission ${role.label}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title={`Permission — ${role.label}`}
                    description={`${formatNumber(granted.length)} dari ${formatNumber(total)} permission diberikan ke role ini.`}
                />

                <Form
                    {...RoleController.update.form(role.id)}
                    options={{ preserveScroll: true }}
                    resetOnSuccess={false}
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-4 xl:grid-cols-2">
                                {groups.map((group) => {
                                    const names = group.permissions.map(
                                        (permission) => permission.name,
                                    );
                                    const grantedInGroup = names.filter(
                                        (name) => granted.includes(name),
                                    ).length;
                                    const allGranted =
                                        grantedInGroup === names.length;

                                    return (
                                        <Card key={group.key}>
                                            <CardHeader>
                                                <CardTitle className="flex items-center justify-between gap-3 text-base">
                                                    {group.label}
                                                    <span className="text-xs font-normal text-muted-foreground tabular-nums">
                                                        {formatNumber(
                                                            grantedInGroup,
                                                        )}
                                                        /
                                                        {formatNumber(
                                                            names.length,
                                                        )}
                                                    </span>
                                                </CardTitle>
                                                <CardDescription>
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            toggleGroup(
                                                                group,
                                                                !allGranted,
                                                            )
                                                        }
                                                        className="underline underline-offset-4 hover:text-foreground"
                                                    >
                                                        {allGranted
                                                            ? 'Hapus semua di grup ini'
                                                            : 'Pilih semua di grup ini'}
                                                    </button>
                                                </CardDescription>
                                            </CardHeader>
                                            <CardContent className="flex flex-col gap-3">
                                                <Separator />
                                                {group.permissions.map(
                                                    (permission) => (
                                                        <div
                                                            key={
                                                                permission.name
                                                            }
                                                            className="flex items-start gap-3"
                                                        >
                                                            <Checkbox
                                                                id={
                                                                    permission.name
                                                                }
                                                                checked={granted.includes(
                                                                    permission.name,
                                                                )}
                                                                onCheckedChange={(
                                                                    checked,
                                                                ) =>
                                                                    toggle(
                                                                        permission.name,
                                                                        checked ===
                                                                            true,
                                                                    )
                                                                }
                                                            />
                                                            {granted.includes(
                                                                permission.name,
                                                            ) && (
                                                                <input
                                                                    type="hidden"
                                                                    name="permissions[]"
                                                                    value={
                                                                        permission.name
                                                                    }
                                                                />
                                                            )}
                                                            <div className="grid gap-0.5">
                                                                <Label
                                                                    htmlFor={
                                                                        permission.name
                                                                    }
                                                                    className="capitalize"
                                                                >
                                                                    {
                                                                        permission.action
                                                                    }
                                                                </Label>
                                                                <span className="font-mono text-xs text-muted-foreground">
                                                                    {
                                                                        permission.name
                                                                    }
                                                                </span>
                                                            </div>
                                                        </div>
                                                    ),
                                                )}
                                            </CardContent>
                                        </Card>
                                    );
                                })}
                            </div>

                            <InputError message={errors.permissions} />

                            <div className="sticky bottom-4 flex flex-wrap items-center gap-3 rounded-lg border bg-background/95 p-4 shadow-sm backdrop-blur">
                                <Button type="submit" disabled={processing}>
                                    Simpan Permission
                                </Button>
                                <Button variant="ghost" asChild>
                                    <Link href={RoleController.index()}>
                                        Kembali ke daftar role
                                    </Link>
                                </Button>
                                <span className="text-sm text-muted-foreground tabular-nums">
                                    {formatNumber(granted.length)} permission
                                    dipilih
                                </span>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
