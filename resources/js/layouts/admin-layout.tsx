import {
    ArrowRightLeft,
    ChartColumn,
    ChartLine,
    Contact,
    Receipt,
    ScrollText,
    Trophy,
    Warehouse,
    Banknote,
    Boxes,
    ClipboardList,
    FolderTree,
    LayoutGrid,
    Package,
    PackageMinus,
    PackagePlus,
    Palette,
    Images,
    Percent,
    MessageSquare,
    Star,
    ShieldCheck,
    ShoppingCart,
    Store,
    TicketPercent,
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
import reports from '@/routes/admin/reports';
import { index as rolesIndex } from '@/routes/admin/roles';
import { index as usersIndex } from '@/routes/admin/users';
import { index as vouchersIndex } from '@/routes/admin/vouchers';
import { index as promotionsIndex } from '@/routes/admin/promotions';
import { index as bannersIndex } from '@/routes/admin/banners';
import { index as reviewsIndex } from '@/routes/admin/reviews';
import { index as featuredProductsIndex } from '@/routes/admin/featured-products';
import stockEntries from '@/routes/branch/stock-entries';
import stockIssues from '@/routes/branch/stock-issues';
import stockOpnames from '@/routes/branch/stock-opnames';
import stockTransfers from '@/routes/branch/stock-transfers';
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
        // Central only: `VoucherPolicy` demands a central actor on top of the
        // permission, so the item is hidden from anyone it would refuse.
        label: 'Marketing',
        items: [
            {
                title: 'Voucher',
                href: vouchersIndex(),
                icon: TicketPercent,
                permission: 'vouchers.manage',
            },
            {
                title: 'Promo Produk',
                href: promotionsIndex(),
                icon: Percent,
                permission: 'promotions.manage',
            },
            {
                title: 'Banner',
                href: bannersIndex(),
                icon: Images,
                permission: 'banners.manage',
            },
            {
                title: 'Produk Unggulan',
                href: featuredProductsIndex(),
                icon: Star,
                permission: 'products.update',
            },
            {
                title: 'Ulasan',
                href: reviewsIndex(),
                icon: MessageSquare,
                permission: 'reviews.moderate',
            },
        ],
    },
    {
        // Every report reads through the branch global scope, so the same
        // screens serve central and branch staff; each item carries its own
        // report permission (P2-E7, `ReportCatalog::REPORTS`).
        label: 'Laporan',
        items: [
            {
                title: 'Penjualan',
                href: reports.sales(),
                icon: ChartLine,
                permission: 'reports.sales.view',
            },
            {
                title: 'Transaksi',
                href: reports.transactions(),
                icon: Receipt,
                permission: 'reports.sales.view',
            },
            {
                title: 'Produk Terlaris',
                href: reports.products(),
                icon: Trophy,
                permission: 'reports.product.view',
            },
            {
                title: 'Laporan Pelanggan',
                href: reports.customers(),
                icon: Contact,
                permission: 'reports.customer.view',
            },
            {
                title: 'Posisi Stok',
                href: reports.stock(),
                icon: Warehouse,
                permission: 'reports.stock.view',
            },
            {
                title: 'Kartu Stok',
                href: reports.stockCard(),
                icon: ScrollText,
                permission: 'reports.stock.view',
            },
            {
                title: 'Performa Cabang',
                href: reports.branches(),
                icon: ChartColumn,
                permission: 'reports.branch.view',
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
