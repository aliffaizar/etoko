import { Link } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import { NavUser } from '@/components/nav-user';
import { NavGroups } from '@/components/panel/nav-group';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useAuthorization } from '@/hooks/use-authorization';
import type { NavItem, PanelNavGroup } from '@/types';

export function PanelSidebar({
    groups,
    homeHref,
}: {
    groups: PanelNavGroup[];
    homeHref: NavItem['href'];
}) {
    const { auth } = useAuthorization();

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={homeHref} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>

                <p className="truncate px-2 text-xs text-sidebar-foreground/60 group-data-[collapsible=icon]:hidden">
                    {auth?.branch
                        ? `${auth.branch.name} (${auth.branch.code})`
                        : (auth?.roleLabel ?? 'Pusat')}
                </p>
            </SidebarHeader>

            <SidebarContent>
                <NavGroups groups={groups} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
