import { LayoutGrid } from 'lucide-react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { PanelSidebar } from '@/components/panel/panel-sidebar';
import { dashboard } from '@/routes/admin';
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
