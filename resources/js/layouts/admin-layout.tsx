import {
    FolderTree,
    LayoutGrid,
    Package,
    Palette,
    ShieldCheck,
    Store,
    Users,
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
import { index as rolesIndex } from '@/routes/admin/roles';
import { index as usersIndex } from '@/routes/admin/users';
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
