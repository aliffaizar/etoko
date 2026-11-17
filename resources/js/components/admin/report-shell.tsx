import { Deferred, Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import Heading from '@/components/heading';
import { ReportTabs, type ReportKey } from '@/components/admin/report-toolbar';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import reports from '@/routes/admin/reports';
import type { BreadcrumbItem } from '@/types';

/**
 * The frame every report page shares: title, report tabs, toolbar, and the
 * deferred figures behind a skeleton (P2-E7).
 */
export function ReportShell({
    reportKey,
    title,
    description,
    toolbar,
    children,
}: {
    reportKey: ReportKey;
    title: string;
    description: string;
    toolbar: ReactNode;
    children: ReactNode;
}) {
    return (
        <>
            <Head title={`Laporan ${title}`} />

            <div className="flex h-full min-w-0 flex-1 flex-col gap-6 p-4">
                <Heading title={`Laporan ${title}`} description={description} />
                <ReportTabs current={reportKey} />
                {toolbar}

                <Deferred data="report" fallback={<ReportSkeleton />}>
                    {children}
                </Deferred>
            </div>
        </>
    );
}

export function ReportSkeleton() {
    return (
        <div
            className="flex flex-col gap-4"
            aria-busy="true"
            aria-label="Memuat laporan"
        >
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {Array.from({ length: 4 }, (_, index) => (
                    <Skeleton key={index} className="h-24" />
                ))}
            </div>
            <Card>
                <CardHeader>
                    <Skeleton className="h-5 w-48" />
                </CardHeader>
                <CardContent>
                    <Skeleton className="h-64 w-full" />
                </CardContent>
            </Card>
        </div>
    );
}

/** Breadcrumbs for a report page. */
export function reportBreadcrumbs(
    title: string,
    href: BreadcrumbItem['href'],
): BreadcrumbItem[] {
    return [
        { title: 'Laporan', href: reports.sales() },
        { title, href },
    ];
}
