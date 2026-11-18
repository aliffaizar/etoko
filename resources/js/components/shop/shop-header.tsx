import { Link, router, usePage } from '@inertiajs/react';
import {
    ChevronDown,
    Heart,
    LayoutGrid,
    MapPin,
    Menu,
    Package,
    Search,
    ShoppingCart,
    Store,
} from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import AppLogo from '@/components/app-logo';
import { BranchPicker } from '@/components/shop/branch-picker';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { UserMenuContent } from '@/components/user-menu-content';
import { useAuthorization } from '@/hooks/use-authorization';
import { useInitials } from '@/hooks/use-initials';
import HostLink from '@/components/host-link';
import { home, login, register } from '@/routes';
import { dashboard as adminDashboard } from '@/routes/admin';
import { dashboard as branchDashboard } from '@/routes/branch';
import account from '@/routes/shop/account';
import cart from '@/routes/shop/cart';
import catalog from '@/routes/shop/catalog';
import type { ShopContext } from '@/types';

/**
 * The storefront's header.
 *
 * Three things have to be reachable from every page: the branch the shopper is
 * buying from — because it decides every price and stock figure on screen
 * (BR-8) — the search box, and the category menu. The cart and the wishlist
 * sit beside them, which is why the right-hand side is a row rather than a
 * single button; the order history lives in the account menu, where somebody
 * looking for "my orders" expects it.
 *
 * The staff shortcut is deliberate: an admin who lands on the shop should be
 * one click from their panel instead of hunting for a URL.
 */
export function ShopHeader() {
    const shop = usePage().props.shop as ShopContext | undefined;
    const categories = shop?.categories ?? [];

    return (
        <header className="sticky top-0 z-40 border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/80">
            <div className="mx-auto flex h-16 max-w-7xl items-center gap-3 px-4">
                <MobileMenu categories={categories} />

                <Link
                    href={home()}
                    className="flex shrink-0 items-center gap-2"
                    aria-label="Beranda"
                >
                    <AppLogo />
                </Link>

                <CatalogSearch className="hidden flex-1 md:block" />

                <div className="ml-auto flex items-center gap-1 md:ml-0">
                    <BranchPicker className="hidden sm:flex" />
                    <ShopperActions cartCount={shop?.cart_count ?? 0} />
                    <AccountMenu />
                </div>
            </div>

            <CategoryBar categories={categories} />

            <div className="border-t px-4 py-2 md:hidden">
                <CatalogSearch />
                <BranchPicker className="mt-1 sm:hidden" />
            </div>
        </header>
    );
}

/**
 * The search box, which navigates to the catalogue rather than filtering in
 * place.
 *
 * Submitted on Enter rather than on every keystroke, and submitted as a plain
 * navigation, so the result is a bookmarkable, shareable URL — the same page a
 * shopper reaches by browsing (P1-E5-T5). The debounced, type-as-you-go search
 * lives on the catalogue screen itself, where the grid is already on screen to
 * react to it; doing it here would navigate away mid-word from whatever page
 * the shopper is on.
 */
function CatalogSearch({ className }: { className?: string }) {
    const page = usePage();
    const inputId = useId();
    const currentSearch = new URL(
        page.url,
        'http://localhost',
    ).searchParams.get('search');
    const [draft, setDraft] = useState(currentSearch ?? '');

    // Follow the URL when it changes elsewhere, e.g. the back button or a
    // filter reset on the catalogue page.
    useEffect(() => setDraft(currentSearch ?? ''), [currentSearch]);

    return (
        <form
            className={className}
            onSubmit={(event) => {
                event.preventDefault();
                router.get(
                    catalog.index.url({ query: { search: draft || null } }),
                );
            }}
        >
            <Label htmlFor={inputId} className="sr-only">
                Cari produk
            </Label>
            <div className="relative">
                <Search
                    aria-hidden
                    className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    id={inputId}
                    type="search"
                    name="search"
                    value={draft}
                    placeholder="Cari produk…"
                    onChange={(event) => setDraft(event.target.value)}
                    className="pl-8"
                />
            </div>
        </form>
    );
}

function CategoryBar({
    categories,
}: {
    categories: ShopContext['categories'];
}) {
    if (categories.length === 0) {
        return null;
    }

    return (
        <nav aria-label="Kategori produk" className="hidden border-t md:block">
            <ul className="mx-auto flex max-w-7xl items-center gap-1 overflow-x-auto px-4">
                <li>
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={catalog.index()} prefetch>
                            <LayoutGrid aria-hidden />
                            Semua Produk
                        </Link>
                    </Button>
                </li>

                {categories.map((category) => (
                    <li key={category.slug}>
                        {category.children.length === 0 ? (
                            <Button variant="ghost" size="sm" asChild>
                                <Link
                                    href={catalog.category(category.slug)}
                                    prefetch
                                >
                                    {category.name}
                                </Link>
                            </Button>
                        ) : (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="ghost" size="sm">
                                        {category.name}
                                        <ChevronDown aria-hidden />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="start">
                                    <Link
                                        href={catalog.category(category.slug)}
                                        className="block rounded-sm px-2 py-1.5 text-sm font-medium hover:bg-accent"
                                    >
                                        Semua {category.name}
                                    </Link>
                                    {category.children.map((child) => (
                                        <Link
                                            key={child.slug}
                                            href={catalog.category(child.slug)}
                                            className="block rounded-sm px-2 py-1.5 text-sm hover:bg-accent"
                                        >
                                            {child.name}
                                        </Link>
                                    ))}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                    </li>
                ))}
            </ul>
        </nav>
    );
}

function MobileMenu({ categories }: { categories: ShopContext['categories'] }) {
    return (
        <Sheet>
            <SheetTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="md:hidden"
                    aria-label="Buka menu kategori"
                >
                    <Menu aria-hidden />
                </Button>
            </SheetTrigger>
            <SheetContent side="left" className="w-72 overflow-y-auto">
                <SheetHeader>
                    <SheetTitle>Kategori</SheetTitle>
                </SheetHeader>

                <nav className="flex flex-col gap-1 p-4">
                    <Link
                        href={catalog.index()}
                        className="rounded-md px-2 py-2 text-sm font-medium hover:bg-accent"
                    >
                        Semua Produk
                    </Link>

                    {categories.map((category) => (
                        <div key={category.slug} className="flex flex-col">
                            <Link
                                href={catalog.category(category.slug)}
                                className="rounded-md px-2 py-2 text-sm font-medium hover:bg-accent"
                            >
                                {category.name}
                            </Link>
                            {category.children.map((child) => (
                                <Link
                                    key={child.slug}
                                    href={catalog.category(child.slug)}
                                    className="rounded-md px-2 py-1.5 pl-5 text-sm text-muted-foreground hover:bg-accent"
                                >
                                    {child.name}
                                </Link>
                            ))}
                        </div>
                    ))}
                </nav>
            </SheetContent>
        </Sheet>
    );
}

/**
 * The shopper's own things: wishlist and cart (P1-E6), reachable from every
 * page.
 *
 * The badge counts **units**, the same figure every "N item" in the
 * application prints — a badge reading "1" beside a cart summary reading
 * "4 item" is a disagreement nobody can resolve by looking.
 *
 * The wishlist needs an account, so it is only offered to one; the cart works
 * for guests and is always there.
 */
function ShopperActions({ cartCount }: { cartCount: number }) {
    const { auth } = useAuthorization();

    return (
        <div className="flex items-center gap-1">
            {auth?.user && !auth.isStaff && (
                <Button variant="ghost" size="icon" asChild>
                    <Link
                        href={account.wishlist.index()}
                        aria-label="Wishlist"
                        title="Wishlist"
                    >
                        <Heart aria-hidden />
                    </Link>
                </Button>
            )}

            <Button variant="ghost" size="icon" className="relative" asChild>
                <Link
                    href={cart.index()}
                    aria-label={
                        cartCount === 0
                            ? 'Keranjang, kosong'
                            : `Keranjang, ${cartCount} item`
                    }
                    title="Keranjang"
                >
                    <ShoppingCart aria-hidden />
                    {cartCount > 0 && (
                        <span
                            aria-hidden
                            className="absolute -end-0.5 -top-0.5 flex size-4.5 items-center justify-center rounded-full bg-primary px-1 text-[0.625rem] font-medium text-primary-foreground tabular-nums"
                        >
                            {cartCount > 9 ? '9+' : cartCount}
                        </span>
                    )}
                </Link>
            </Button>
        </div>
    );
}

function AccountMenu() {
    const { auth } = useAuthorization();
    const getInitials = useInitials();

    if (!auth?.user) {
        return (
            <div className="flex items-center gap-1">
                <Button variant="ghost" size="sm" asChild>
                    <Link href={login()}>Masuk</Link>
                </Button>
                <Button size="sm" asChild>
                    <Link href={register()}>Daftar</Link>
                </Button>
            </div>
        );
    }

    return (
        <>
            {auth.isStaff && (
                <Button
                    variant="ghost"
                    size="sm"
                    asChild
                    className="hidden sm:flex"
                >
                    {/* The panel may be on a host of its own (ADR-063),
                        where the staff member signs in again. */}
                    <HostLink
                        host="panel"
                        href={
                            auth.isCentral
                                ? adminDashboard()
                                : branchDashboard()
                        }
                    >
                        <Store aria-hidden />
                        Panel
                    </HostLink>
                </Button>
            )}

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="rounded-full"
                        aria-label="Menu akun"
                    >
                        <Avatar className="size-8">
                            <AvatarImage
                                src={auth.user.avatar}
                                alt={auth.user.name}
                            />
                            <AvatarFallback>
                                {getInitials(auth.user.name)}
                            </AvatarFallback>
                        </Avatar>
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                    <UserMenuContent
                        user={auth.user}
                        // A shopper's first question about their account is
                        // "where is my order?", so the history sits above
                        // settings. Staff accounts have no shop history to
                        // show (P1-E7-T6).
                        extraItems={
                            auth.isStaff
                                ? undefined
                                : [
                                      {
                                          label: 'Pesanan Saya',
                                          href: account.orders.index().url,
                                          icon: Package,
                                      },
                                      {
                                          label: 'Alamat Saya',
                                          href: account.addresses.index().url,
                                          icon: MapPin,
                                      },
                                  ]
                        }
                    />
                </DropdownMenuContent>
            </DropdownMenu>
        </>
    );
}
