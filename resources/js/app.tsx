import { createInertiaApp } from '@inertiajs/react';
import type { ComponentType } from 'react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AdminLayout from '@/layouts/admin-layout';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import BranchLayout from '@/layouts/branch-layout';
import SettingsLayout from '@/layouts/settings/layout';
import ShopLayout from '@/layouts/shop-layout';
import type { Auth } from '@/types/auth';
import type { AppLayoutProps } from '@/types/ui';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

/**
 * The shell the account settings screens are drawn inside.
 *
 * Settings are not an area of their own: a panel user changing their password
 * stays in their panel, keeping its menu, instead of being dropped into the
 * shop's shell with no way back to the work they were doing (BUG-06). The page
 * object carries the shared `auth` props, so the choice follows the role rather
 * than the page name.
 */
function shellFor(auth: Auth | undefined): ComponentType<AppLayoutProps> {
    if (auth?.isCentral) {
        return AdminLayout;
    }

    return auth?.isStaff ? BranchLayout : AppLayout;
}

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name, page) => {
        switch (true) {
            // The error page stands on its own: it is reached on routes whose
            // layout the user may not be allowed to see at all.
            case name === 'error':
                return null;
            case name.startsWith('shop/'):
                return ShopLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('admin/'):
                return AdminLayout;
            case name.startsWith('branch/'):
                return BranchLayout;
            case name.startsWith('settings/'):
                // The layout callback is handed the untyped `Page`, so the
                // shared props need naming here.
                return [
                    shellFor(page.props.auth as Auth | undefined),
                    SettingsLayout,
                ];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
