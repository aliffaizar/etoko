import { Head, Link } from '@inertiajs/react';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import { UserForm } from '@/components/admin/user-form';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { create, index as usersIndex } from '@/routes/admin/users';
import type { StaffFormOptions } from '@/types';

type UsersCreateProps = {
    options: StaffFormOptions;
};

export default function UsersCreate({ options }: UsersCreateProps) {
    return (
        <>
            <Head title="Tambah Pengguna" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Tambah Pengguna"
                    description="Buat akun staf baru dan tentukan role serta cabangnya."
                />

                <Card className="max-w-3xl">
                    <CardContent>
                        <UserForm
                            action={UserController.store.form()}
                            options={options}
                            submitLabel="Simpan Pengguna"
                            secondaryAction={
                                <Button variant="ghost" asChild>
                                    <Link href={UserController.index()}>
                                        Batal
                                    </Link>
                                </Button>
                            }
                        />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

UsersCreate.layout = {
    breadcrumbs: [
        { title: 'Pengguna', href: usersIndex() },
        { title: 'Tambah', href: create() },
    ],
};
