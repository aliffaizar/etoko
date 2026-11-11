import {
    Banknote,
    Boxes,
    ClipboardList,
    FolderTree,
    LayoutGrid,
    Package,
    PackageMinus,
    PackagePlus,
    Palette,
    ShieldCheck,
    ShoppingCart,
    Store,
    UserCheck,
    Undo2,
    Users,
    Wallet,
} from 'lucide-react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { PanelSidebar } from '@/components/panel/panel-sidebar';
import { dashboard } from '@/routes/admin';
import { index as branchesIndex } from '@/routes/admin/branches';
import { index as categoriesIndex } from '@/routes/admin/categories';
import { index as attributesIndex } from '@/routes/admin/product-attributes';
import { index as productsIndex } from '@/routes/admin/products';
import { index as customersIndex } from '@/routes/admin/customers';
import { index as ordersIndex } from '@/routes/admin/orders';
import { index as paymentsIndex } from '@/routes/admin/payments';
import { index as refundsIndex } from '@/routes/admin/refunds';
import { index as returnsIndex } from '@/routes/admin/returns';
import { index as rolesIndex } from '@/routes/admin/roles';
import { index as usersIndex } from '@/routes/admin/users';
import stockEntries from '@/routes/branch/stock-entries';
import stockIssues from '@/routes/branch/stock-issues';
import stockOpnames from '@/routes/branch/stock-opnames';
import stocks from '@/routes/branch/stocks';
import type { AppLayoutProps, PanelNavGroup } from '@/types';

/**
 * Menu of the central panel, following the structure in the project brief.
 *
 * Each epic adds its own entries here as the screens are built; see
 * docs/05-roadmap.md. An item is only listed once its route exists, so the menu
 * never links to a page that is not there.
 */
const navGroups: PanelNavGroup[] = [
    {
        label: 'Umum',
        items: [
            {
                title: 'Dashboard',
                href: dashboard(),
                icon: LayoutGrid,
                permission: 'dashboard.central.view',
            },
        ],
    },
    {
        label: 'Master Data',
        items: [
            {
                title: 'Produk',
                href: productsIndex(),
                icon: Package,
                permission: 'products.view',
            },
            {
                title: 'Kategori',
                href: categoriesIndex(),
                icon: FolderTree,
                permission: 'categories.view',
            },
            {
                title: 'Atribut Produk',
                href: attributesIndex(),
                icon: Palette,
                permission: 'products.view',
            },
            {
                title: 'Cabang',
                href: branchesIndex(),
                icon: Store,
                permission: 'branches.view',
            },
        ],
    },
    {
        // Stock lives in the branch panel, because stock lives in a branch.
        // Central staff supervise it there with a branch selector, rather than
        // through a second screen that would have to agree with the first
        // (P1-E4-T3).
        label: 'Inventaris',
        items: [
            {
                title: 'Stok Cabang',
                href: stocks.index(),
                icon: Boxes,
                permission: 'stocks.view',
            },
            {
                title: 'Barang Masuk',
                href: stockEntries.index(),
                icon: PackagePlus,
                permission: 'stock_entries.view',
            },
            {
                title: 'Barang Keluar',
                href: stockIssues.index(),
                icon: PackageMinus,
                permission: 'stock_issues.view',
            },
            {
                title: 'Stok Opname',
                href: stockOpnames.index(),
                icon: ClipboardList,
                permission: 'stock_opnames.view',
            },
        ],
    },
    {
        // Orders, the money they bring in and the people who place them,
        // together: the first question asked about a customer is what they
        // have ordered, and the first question asked about an order is whether
        // it has been paid for (P1-E7-T8, P2-E1-T4).
        label: 'Penjualan',
        items: [
            {
                title: 'Pesanan',
                href: ordersIndex(),
                icon: ShoppingCart,
                permission: 'orders.view',
            },
            {
                title: 'Pembayaran',
                href: paymentsIndex(),
                icon: Wallet,
                permission: 'payments.view',
            },
            // Retur and Refund are separate items because the brief's admin
            // menu has them separate (brief.md §6), and because they answer
            // different questions: which parcels need me, and which refunds
            // have I promised but not sent. A branch admin holds
            // `returns.view` for both; carrying a refund out is central
            // (ADR-049), so the listing is readable and its buttons are not.
            {
                title: 'Retur',
                href: returnsIndex(),
                icon: Undo2,
                permission: 'returns.view',
            },
            {
                title: 'Refund',
                href: refundsIndex(),
                icon: Banknote,
                permission: 'returns.view',
            },
            {
                title: 'Daftar Pelanggan',
                href: customersIndex(),
                icon: UserCheck,
                permission: 'customers.view',
            },
        ],
    },
    {
        label: 'Pengguna & Akses',
        items: [
            {
                title: 'Pengguna',
                href: usersIndex(),
                icon: Users,
                permission: 'users.view',
            },
            {
                title: 'Role & Permission',
                href: rolesIndex(),
                icon: ShieldCheck,
                permission: 'roles.view',
            },
        ],
    },
];

export default function AdminLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <PanelSidebar groups={navGroups} homeHref={dashboard()} />
            <AppContent variant="sidebar" className="min-w-0 overflow-x-clip">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
            </AppContent>
        </AppShell>
    );
}
