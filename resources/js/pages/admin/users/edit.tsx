import { Form, Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import { UserForm } from '@/components/admin/user-form';
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
import { formatDateTime, formatRelative } from '@/lib/format';
import { edit, index as usersIndex } from '@/routes/admin/users';
import type { StaffDetail, StaffFormOptions } from '@/types';

type UsersEditProps = {
    user: StaffDetail;
    options: StaffFormOptions;
    can: { delete: boolean };
};

export default function UsersEdit({ user, options, can }: UsersEditProps) {
    // The breadcrumb carries the account's name, so it comes from the page's
    // own props rather than a static `UsersEdit.layout` object.
    setLayoutProps({
        breadcrumbs: [
            { title: 'Pengguna', href: usersIndex() },
            { title: user.name, href: edit(user.id) },
        ],
    });

    return (
        <>
            <Head title={`Ubah ${user.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title={user.name}
                    description={`Dibuat ${formatDateTime(user.created_at)} · Login terakhir ${
                        user.last_login_at
                            ? formatRelative(user.last_login_at)
                            : 'belum pernah'
                    }`}
                />

                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Data pengguna</CardTitle>
                        <CardDescription>
                            Mengubah role dapat mengubah cabang dan hak akses
                            pengguna ini.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <UserForm
                            action={UserController.update.form(user.id)}
                            options={options}
                            user={user}
                            submitLabel="Simpan Perubahan"
                            secondaryAction={
                                <Button variant="ghost" asChild>
                                    <Link href={UserController.index()}>
                                        Kembali ke daftar
                                    </Link>
                                </Button>
                            }
                        />
                    </CardContent>
                </Card>

                <ResetPasswordCard userId={user.id} />

                {can.delete && <DeleteCard user={user} />}
            </div>
        </>
    );
}

function ResetPasswordCard({ userId }: { userId: number }) {
    return (
        <Card className="max-w-3xl">
            <CardHeader>
                <CardTitle>Atur ulang kata sandi</CardTitle>
                <CardDescription>
                    Kata sandi baru langsung berlaku dan sesi "ingat saya" milik
                    pengguna ini dicabut. Sampaikan kata sandi lewat kanal yang
                    aman.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    {...UserController.updatePassword.form(userId)}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-6 md:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="new_password">
                                        Kata sandi baru
                                    </Label>
                                    <Input
                                        id="new_password"
                                        name="password"
                                        type="password"
                                        required
                                        autoComplete="new-password"
                                    />
                                    <InputError message={errors.password} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="new_password_confirmation">
                                        Ulangi kata sandi baru
                                    </Label>
                                    <Input
                                        id="new_password_confirmation"
                                        name="password_confirmation"
                                        type="password"
                                        required
                                        autoComplete="new-password"
                                    />
                                </div>
                            </div>

                            <div>
                                <Button
                                    type="submit"
                                    variant="secondary"
                                    disabled={processing}
                                >
                                    Atur Ulang Kata Sandi
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </CardContent>
        </Card>
    );
}

function DeleteCard({ user }: { user: StaffDetail }) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    return (
        <Card className="max-w-3xl border-destructive/40">
            <CardHeader>
                <CardTitle>Hapus pengguna</CardTitle>
                <CardDescription>
                    Akun dihapus secara lunak: riwayat yang dibuatnya tetap utuh
                    dan alamat emailnya belum bisa dipakai akun lain.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Button variant="destructive" onClick={() => setOpen(true)}>
                    <Trash2 aria-hidden />
                    Hapus {user.name}
                </Button>

                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Hapus pengguna?</DialogTitle>
                            <DialogDescription>
                                Akun <strong>{user.name}</strong> tidak akan
                                bisa masuk lagi. Tindakan ini dicatat di audit
                                log.
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
                                        UserController.destroy(user.id).url,
                                        {
                                            onStart: () => setProcessing(true),
                                            onFinish: () =>
                                                setProcessing(false),
                                        },
                                    )
                                }
                            >
                                Hapus pengguna
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </CardContent>
        </Card>
    );
}
