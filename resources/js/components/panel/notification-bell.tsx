import { Link, router, usePage } from '@inertiajs/react';
import { Bell, CheckCheck } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatRelative } from '@/lib/format';
import { cn } from '@/lib/utils';
import notifications from '@/routes/branch/notifications';
import type { NotificationItem } from '@/types';

/**
 * The panel's notification bell (P2-E6-T4).
 *
 * Fed by the `notificationBell` shared prop, so it is fresh on every visit
 * without a request of its own. Opening an entry is a POST that marks it read
 * and redirects to what it is about; the server decides the destination, so a
 * notification with nothing to open simply stays on the page.
 */
export function NotificationBell() {
    const { notificationBell: bell } = usePage().props;

    if (!bell) {
        return null;
    }

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

    const badge = bell.unread > 99 ? '99+' : String(bell.unread);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="relative"
                    aria-label={
                        bell.unread > 0
                            ? `Notifikasi, ${bell.unread} belum dibaca`
                            : 'Notifikasi'
                    }
                >
                    <Bell aria-hidden />
                    {bell.unread > 0 && (
                        <span className="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] leading-none font-semibold text-white tabular-nums">
                            {badge}
                        </span>
                    )}
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent
                align="end"
                className="w-[min(22rem,calc(100vw-2rem))] p-0"
            >
                <div className="flex items-center justify-between gap-2 px-3 py-2">
                    <DropdownMenuLabel className="p-0 text-sm">
                        Notifikasi
                    </DropdownMenuLabel>
                    {bell.unread > 0 && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 px-2 text-xs"
                            onClick={markAllRead}
                        >
                            <CheckCheck aria-hidden />
                            Tandai semua dibaca
                        </Button>
                    )}
                </div>
                <DropdownMenuSeparator className="my-0" />

                {bell.recent.length === 0 ? (
                    <p className="px-3 py-6 text-center text-sm text-muted-foreground">
                        Belum ada notifikasi.
                    </p>
                ) : (
                    <div className="max-h-96 overflow-y-auto py-1">
                        {bell.recent.map((item) => (
                            <DropdownMenuItem
                                key={item.id}
                                className="flex cursor-pointer items-start gap-2.5 rounded-none px-3 py-2.5"
                                onSelect={() => open(item)}
                            >
                                <span
                                    aria-hidden
                                    className={cn(
                                        'mt-1.5 size-2 shrink-0 rounded-full',
                                        item.is_read
                                            ? 'bg-transparent'
                                            : 'bg-primary',
                                    )}
                                />
                                <span className="flex min-w-0 flex-col gap-0.5">
                                    <span className="text-xs font-medium text-muted-foreground">
                                        {item.title} ·{' '}
                                        {formatRelative(item.created_at)}
                                    </span>
                                    <span
                                        className={cn(
                                            'text-sm leading-snug whitespace-normal',
                                            !item.is_read && 'font-medium',
                                        )}
                                    >
                                        {item.message}
                                    </span>
                                    {!item.is_read && (
                                        <span className="sr-only">
                                            (belum dibaca)
                                        </span>
                                    )}
                                </span>
                            </DropdownMenuItem>
                        ))}
                    </div>
                )}

                <DropdownMenuSeparator className="my-0" />
                <DropdownMenuItem
                    asChild
                    className="justify-center rounded-none py-2.5 text-sm"
                >
                    <Link href={notifications.index().url}>Lihat semua</Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
