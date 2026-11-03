import type { Auth } from '@/types/auth';
import type { BreadcrumbItem } from '@/types/navigation';

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
