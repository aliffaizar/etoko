import type { ReactNode } from 'react';
import type { BreadcrumbItem } from '@/types/navigation';

export type AppLayoutProps = {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
};

export type AppVariant = 'header' | 'sidebar';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};

/**
 * One rejected CSV row of a product import (P1-E3-T11).
 */
export type ProductImportRowError = {
    line: number;
    messages: string[];
};

/**
 * What a product CSV import did, flashed back to the screen that started it.
 *
 * An import is all-or-nothing, so `was_applied` tells the two outcomes apart:
 * it ran and the counts are real, or it refused and `errors` says why.
 */
export type ProductImportReport = {
    was_applied: boolean;
    created: number;
    updated: number;
    errors: ProductImportRowError[];
    summary: string;
};

/**
 * Flash data the backend sends with `Inertia::flash()`.
 *
 * Declared as a shared page prop in `types/global.d.ts`, so a page reads
 * `usePage().props.flash` without casting.
 */
export type FlashData = {
    toast?: FlashToast;
    import?: ProductImportReport;
};

export type AuthLayoutProps = {
    children?: ReactNode;
    name?: string;
    title?: string;
    description?: string;
};
