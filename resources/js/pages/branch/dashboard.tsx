import { Head } from '@inertiajs/react';
import { Users, Warehouse } from 'lucide-react';
import Heading from '@/components/heading';
import { StatCard } from '@/components/panel/stat-card';
import { useAuthorization } from '@/hooks/use-authorization';
import { dashboard } from '@/routes/branch';

type DashboardProps = {
    stats: {
        warehouses: number;
        staff: number;
    };
};

export default function BranchDashboard({ stats }: DashboardProps) {
    const { auth } = useAuthorization();

    return (
        <>
            <Head title="Dashboard Cabang" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Dashboard Cabang"
                    description={
                        auth?.branch
                            ? `Operasional ${auth.branch.name}.`
                            : 'Operasional cabang.'
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Gudang"
                        value={stats.warehouses}
                        icon={Warehouse}
                    />
                    <StatCard
                        label="Staf Cabang"
                        value={stats.staff}
                        icon={Users}
                    />
                </div>

                <p className="text-sm text-muted-foreground">
                    Penjualan cabang, pesanan masuk, stok produk, dan barang
                    masuk/keluar ditambahkan pada epic P1-E4 dan P2-E7.
                </p>
            </div>
        </>
    );
}

BranchDashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
