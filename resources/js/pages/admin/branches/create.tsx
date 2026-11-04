import { Head, Link } from '@inertiajs/react';
import BranchController from '@/actions/App/Http/Controllers/Admin/BranchController';
import { BranchForm } from '@/components/admin/branch-form';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { create, index as branchesIndex } from '@/routes/admin/branches';
import type { BranchFormOptions } from '@/types';

type BranchesCreateProps = {
    options: BranchFormOptions;
};

export default function BranchesCreate({ options }: BranchesCreateProps) {
    return (
        <>
            <Head title="Tambah Cabang" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Tambah Cabang"
                    description="Cabang baru langsung mendapat satu gudang utama dan jam operasional standar, yang bisa diubah setelah tersimpan."
                />

                <Card className="max-w-4xl">
                    <CardContent>
                        <BranchForm
                            action={BranchController.store.form()}
                            options={options}
                            submitLabel="Simpan Cabang"
                            secondaryAction={
                                <Button variant="ghost" asChild>
                                    <Link href={BranchController.index()}>
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

BranchesCreate.layout = {
    breadcrumbs: [
        { title: 'Cabang', href: branchesIndex() },
        { title: 'Tambah', href: create() },
    ],
};
