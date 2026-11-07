import { Link, router } from '@inertiajs/react';
import { LogOut, Settings, type LucideIcon } from 'lucide-react';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

/** One extra entry a host can put above "Pengaturan". */
export type UserMenuExtraItem = {
    label: string;
    href: string;
    icon: LucideIcon;
};

type Props = {
    user: User;
    /**
     * Entries the host adds for this user, shown above "Pengaturan".
     *
     * The storefront puts the shopper's orders and addresses here; the panels
     * pass nothing, because their navigation is the sidebar.
     */
    extraItems?: UserMenuExtraItem[];
};

export function UserMenuContent({ user, extraItems }: Props) {
    const cleanup = useMobileNavigation();

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                {extraItems?.map((item) => (
                    <DropdownMenuItem key={item.href} asChild>
                        <Link
                            className="block w-full cursor-pointer"
                            href={item.href}
                            onClick={cleanup}
                        >
                            <item.icon className="mr-2" />
                            {item.label}
                        </Link>
                    </DropdownMenuItem>
                ))}
                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full cursor-pointer"
                        href={edit()}
                        prefetch
                        onClick={cleanup}
                    >
                        <Settings className="mr-2" />
                        Pengaturan
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link
                    className="block w-full cursor-pointer"
                    href={logout()}
                    as="button"
                    onClick={handleLogout}
                    data-test="logout-button"
                >
                    <LogOut className="mr-2" />
                    Keluar
                </Link>
            </DropdownMenuItem>
        </>
    );
}
