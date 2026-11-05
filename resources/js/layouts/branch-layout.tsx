import {
    Boxes,
    ClipboardList,
    FolderTree,
    PackageMinus,
    PackagePlus,
    LayoutGrid,
    Package,
    Store,
    Tags,
    Users,
} from 'lucide-react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { PanelSidebar } from '@/components/panel/panel-sidebar';
import { useAuthorization } from '@/hooks/use-authorization';
import { dashboard } from '@/routes/branch';
import stockEntries from '@/routes/branch/stock-entries';
import stockIssues from '@/routes/branch/stock-issues';
import stockOpnames from '@/routes/branch/stock-opnames';
import stocks from '@/routes/branch/stocks';
import { edit as branchEdit } from '@/routes/admin/branches';
import branchPrices from '@/routes/admin/branches/prices';
import branchProducts from '@/routes/admin/branches/products';
import { index as categoriesIndex } from '@/routes/admin/categories';
import { index as productsIndex } from '@/routes/admin/products';
import { index as usersIndex } from '@/routes/admin/users';
import type { AppLayoutProps, PanelNavGroup } from '@/types';

/**
 * Menu of the branch panel. Orders and reports are added by their own epics;
 * see docs/05-roadmap.md.
 *
 * A branch admin manages their own branch and staff on the central screens —
 * the permission matrix gives them `branches.update` and `users.view` for their
 * own branch only (docs/04-rbac.md) — so the menu links there rather than
 * duplicating those screens. Both links are therefore built from the signed-in
 * user's branch, and are absent for central staff supervising the panel, who
 * reach the same screens from `/admin`.
 */
function useBranchNavGroups(): PanelNavGroup[] {
    const { auth } = useAuthorization();

    const groups: PanelNavGroup[] = [
        {
            label: 'Umum',
            items: [
                {
                    title: 'Dashboard',
                    href: dashboard(),
                    icon: LayoutGrid,
                    permission: 'dashboard.branch.view',
                },
            ],
        },
    ];

    // Stock is the branch's own business, unlike the catalogue: a branch admin
    // and their warehouse staff correct their own figures (P1-E4-T3). A
    // cashier holds `stocks.view` and sees the screen read-only.
    groups.push({
        label: 'Inventaris',
        items: [
            {
                title: 'Stok',
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
    });

    // Products and categories are central master data every branch sells from
    // (BR-1), so the branch panel links to the same read-only screens rather
    // than hiding them; each item's permission decides whether it appears.
    // Warehouse staff and cashiers hold `products.view` but not
    // `categories.view`, so they see one item here and not the other.
    groups.push({
        label: 'Katalog',
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
        ],
    });

    if (auth?.branch) {
        groups.push({
            label: 'Cabang Saya',
            items: [
                {
                    title: 'Profil & Gudang',
                    href: branchEdit(auth.branch.id),
                    icon: Store,
                    permission: 'branches.update',
                },
                {
                    title: 'Staf Cabang',
                    href: usersIndex(),
                    icon: Users,
                    permission: 'users.view',
                },
                // What this branch sells from the shared catalogue, and at
                // what price (P1-E3-T9, P1-E3-T10). Read-only here: both are
                // set centrally, and the policies demand a central actor.
                {
                    title: 'Ketersediaan Produk',
                    href: branchProducts.index(auth.branch.id),
                    icon: Package,
                    permission: 'products.view',
                },
                {
                    title: 'Harga Khusus',
                    href: branchPrices.index(auth.branch.id),
                    icon: Tags,
                    permission: 'branch_prices.view',
                },
            ],
        });
    }

    return groups;
}

export default function BranchLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    const navGroups = useBranchNavGroups();

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
