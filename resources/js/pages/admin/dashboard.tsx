import { Head } from '@inertiajs/react';
import { Building2, Store, UserRound, Users } from 'lucide-react';
import Heading from '@/components/heading';
import { StatCard } from '@/components/panel/stat-card';
import { dashboard } from '@/routes/admin';

type DashboardProps = {
    stats: {
        branches: number;
        activeBranches: number;
        staff: number;
        users: number;
    };
};

export default function AdminDashboard({ stats }: DashboardProps) {
    return (
        <>
            <Head title="Dashboard Pusat" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Dashboard Pusat"
                    description="Ringkasan seluruh cabang dan operasional toko."
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Total Cabang"
                        value={stats.branches}
                        hint={`${stats.activeBranches} cabang aktif`}
                        icon={Store}
                    />
                    <StatCard
                        label="Cabang Aktif"
                        value={stats.activeBranches}
                        icon={Building2}
                    />
                    <StatCard
                        label="Staf Cabang"
                        value={stats.staff}
                        icon={Users}
                    />
                    <StatCard
                        label="Total Pengguna"
                        value={stats.users}
                        icon={UserRound}
                    />
                </div>

                <p className="text-sm text-muted-foreground">
                    Metrik penjualan, pesanan masuk, stok menipis, dan grafik
                    performa cabang ditambahkan pada epic P2-E7.
                </p>
            </div>
        </>
    );
}

AdminDashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
