import {
    ArrowRightLeft,
    Banknote,
    Boxes,
    ClipboardList,
    FolderTree,
    PackageMinus,
    PackagePlus,
    LayoutGrid,
    Package,
    PackageCheck,
    ShoppingCart,
    Store,
    Tags,
    Undo2,
    Users,
    Wallet,
} from 'lucide-react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { PanelSidebar } from '@/components/panel/panel-sidebar';
import { useAuthorization } from '@/hooks/use-authorization';
import { dashboard } from '@/routes/branch';
import packing from '@/routes/branch/packing';
import stockEntries from '@/routes/branch/stock-entries';
import stockIssues from '@/routes/branch/stock-issues';
import stockOpnames from '@/routes/branch/stock-opnames';
import stockTransfers from '@/routes/branch/stock-transfers';
import stocks from '@/routes/branch/stocks';
import { edit as branchEdit } from '@/routes/admin/branches';
import branchPrices from '@/routes/admin/branches/prices';
import branchProducts from '@/routes/admin/branches/products';
import { index as categoriesIndex } from '@/routes/admin/categories';
import { index as ordersIndex } from '@/routes/admin/orders';
import { index as paymentsIndex } from '@/routes/admin/payments';
import { index as refundsIndex } from '@/routes/admin/refunds';
import { index as returnsIndex } from '@/routes/admin/returns';
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

    // The branch's order queue, on the same screens central uses (ADR-016).
    // `Order` carries the branch global scope, so the listing arrives already
    // narrowed to this branch and the branch filter is simply of no use here
    // (P1-E7-T8).
    groups.push({
        label: 'Penjualan',
        items: [
            {
                title: 'Pesanan',
                href: ordersIndex(),
                icon: ShoppingCart,
                permission: 'orders.view',
            },
            // The warehouse's view of the same queue (P3-E6-T4): paid orders
            // split per warehouse and shelf. Warehouse staff hold
            // `orders.pack` but not `orders.view`, so for them this is the
            // only order item in the menu.
            {
                title: 'Daftar Kemas',
                href: packing.index(),
                icon: PackageCheck,
                permission: 'orders.pack',
            },
            // The branch's own payment queue, on the same screens central uses
            // (P2-E1-T4). `Payment` carries the branch global scope through a
            // `branch_id` copied from the order, so this arrives narrowed to
            // the branch that has to verify the transfers. A cashier holds
            // `payments.view` and sees it read-only; confirming money needs
            // `payments.verify`.
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
        ],
    });

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
            // Two branches per document, so the listing shows what comes in
            // and what goes out; the policy decides which end may act (P2-E3).
            {
                title: 'Transfer Stok',
                href: stockTransfers.index(),
                icon: ArrowRightLeft,
                permission: 'stock_transfers.view',
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
