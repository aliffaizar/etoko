import type { Auth } from '@/types/auth';
import type { BreadcrumbItem } from '@/types/navigation';
import type { NotificationBell } from '@/types/notification';
import type { ShopContext } from '@/types/shop';
import type { FlashData } from '@/types/ui';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            /**
             * Whether the staff panels answer on this host (ADR-063): false
             * on the storefront once they have a host of their own.
             */
            panelsReachable: boolean;
            /**
             * Each host's root URL, port included, for links that cross
             * between them (`HostLink`); null while there is one host.
             */
            hostRoots: { storefront: string; panel: string } | null;
            /** The panel's bell (P2-E6-T4); null for a non-staff account. */
            notificationBell?: NotificationBell | null;
            /**
             * One-shot data from `Inertia::flash()`: the toast every write
             * sends, and the import report of P1-E3-T11.
             */
            flash?: FlashData;
            /**
             * The chosen branch and the category menu, shared on storefront
             * routes only by `ShareStorefrontContext` — hence optional: a
             * panel page has no shop context at all.
             */
            shop?: ShopContext;
            [key: string]: unknown;
        };
        /**
         * Props a page may hand to the layout the resolver picked for it.
         *
         * Pages with fixed breadcrumbs assign `Page.layout = { breadcrumbs }`;
         * pages whose breadcrumbs or heading depend on their own props call
         * `setLayoutProps` instead. `title` and `description` are read by
         * `AuthLayout`, `breadcrumbs` by `AdminLayout` and `BranchLayout`.
         */
        layoutProps: {
            breadcrumbs: BreadcrumbItem[];
            title: string;
            description: string;
        };
    }
}
