import { Head, router, setLayoutProps } from '@inertiajs/react';
import { Bell, CheckCheck } from 'lucide-react';
import Heading from '@/components/heading';
import { DataTable } from '@/components/panel/data-table';
import { FilterBar } from '@/components/panel/filter-bar';
import { StatusBadge } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { formatDateTime, formatRelative } from '@/lib/format';
import notifications from '@/routes/branch/notifications';
import type {
    DataTableColumn,
    FilterField,
    NotificationItem,
    Paginated,
} from '@/types';

type NotificationsProps = {
    notifications: Paginated<NotificationItem>;
    filter: 'all' | 'unread';
    unread_count: number;
};

const FILTER_FIELDS: FilterField[] = [
    {
        type: 'select',
        key: 'filter',
        label: 'Tampilkan',
        options: [{ value: 'unread', label: 'Belum dibaca' }],
        allLabel: 'Semua notifikasi',
        className: 'sm:w-52',
    },
];

/**
 * Every notification the signed-in staff member has received (P2-E6-T4).
 *
 * The bell shows the latest few; this is the rest. Opening an entry marks it
 * read on the way to what it is about.
 */
export default function Notifications({
    notifications: page,
    unread_count: unreadCount,
}: NotificationsProps) {
    setLayoutProps({
        breadcrumbs: [{ title: 'Notifikasi', href: notifications.index() }],
    });

    const table = useTableQuery({
        only: ['notifications', 'filter', 'unread_count'],
    });

    const open = (item: NotificationItem) => {
        router.post(
            notifications.open(item.id).url,
            {},
            { preserveScroll: true },
        );
    };

    const markAllRead = () => {
        router.post(notifications.read().url, {}, { preserveScroll: true });
    };

    const columns: DataTableColumn<NotificationItem>[] = [
        {
            key: 'message',
            header: 'Notifikasi',
            cell: (row) => (
                <div className="flex min-w-0 flex-col gap-0.5 whitespace-normal">
                    <span className="text-xs font-medium text-muted-foreground">
                        {row.title}
                    </span>
                    <span
                        className={
                            row.is_read ? 'text-sm' : 'text-sm font-medium'
                        }
                    >
                        {row.message}
                    </span>
                </div>
            ),
        },
        {
            key: 'state',
            header: 'Status',
            cell: (row) => (
                <StatusBadge
                    label={row.is_read ? 'Dibaca' : 'Baru'}
                    tone={row.is_read ? 'neutral' : 'info'}
                />
            ),
        },
        {
            key: 'created_at',
            header: 'Waktu',
            cell: (row) => (
                <span
                    className="text-sm whitespace-nowrap text-muted-foreground"
                    title={formatDateTime(row.created_at)}
                >
                    {formatRelative(row.created_at)}
                </span>
            ),
        },
        {
            key: 'actions',
            header: 'Aksi',
            srOnlyHeader: true,
            align: 'right',
            cell: (row) =>
                row.url ? (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => open(row)}
                    >
                        Buka
                    </Button>
                ) : null,
        },
    ];

    return (
        <>
            <Head title="Notifikasi" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Notifikasi"
                    description={
                        unreadCount > 0
                            ? `${unreadCount} notifikasi belum dibaca.`
                            : 'Semua notifikasi sudah dibaca.'
                    }
                />

                <FilterBar
                    fields={FILTER_FIELDS}
                    values={table.values}
                    onChange={table.setFilters}
                    onReset={table.reset}
                    isFiltered={table.isFiltered}
                    actions={
                        unreadCount > 0 ? (
                            <Button variant="outline" onClick={markAllRead}>
                                <CheckCheck aria-hidden />
                                Tandai semua dibaca
                            </Button>
                        ) : undefined
                    }
                />

                <DataTable
                    columns={columns}
                    rows={page.data}
                    rowKey={(row) => row.id}
                    paginator={page}
                    rowLabel="notifikasi"
                    goToPage={table.goToPage}
                    pageUrl={table.pageUrl}
                    isLoading={table.isLoading}
                    empty={{
                        icon: Bell,
                        title: table.isFiltered
                            ? 'Tidak ada notifikasi yang belum dibaca'
                            : 'Belum ada notifikasi',
                        description:
                            'Notifikasi stok menipis, transfer stok, dan pesanan yang perlu diproses muncul di sini.',
                    }}
                />
            </div>
        </>
    );
}
