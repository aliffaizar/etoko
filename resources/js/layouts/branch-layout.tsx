import { LayoutGrid, Store, Users } from 'lucide-react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { PanelSidebar } from '@/components/panel/panel-sidebar';
import { useAuthorization } from '@/hooks/use-authorization';
import { dashboard } from '@/routes/branch';
import { edit as branchEdit } from '@/routes/admin/branches';
import { index as usersIndex } from '@/routes/admin/users';
import type { AppLayoutProps, PanelNavGroup } from '@/types';

/**
 * Menu of the branch panel. Inventory, orders and reports are added by their
 * own epics; see docs/05-roadmap.md.
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
