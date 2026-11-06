import type { ReactNode } from 'react';
import { ShopFooter } from '@/components/shop/shop-footer';
import { ShopHeader } from '@/components/shop/shop-header';

type Props = {
    children: ReactNode;
};

/**
 * The shell every storefront page is drawn in (P0-E2-T6).
 *
 * Deliberately not the sidebar shell the panels use: a shopper navigates by
 * category and search, not by a menu of operations, and the branch picker has
 * to stay in reach on every page because it decides every price and stock
 * figure on screen (BR-8).
 *
 * Built now rather than in `P0-E2` on purpose — it was held back until the
 * pages that use it existed, so its header could be shaped by what they
 * actually need (docs/05-roadmap.md).
 *
 * The header and footer read the chosen branch and the category menu from the
 * `shop` prop, which `ShareStorefrontContext` shares on every storefront
 * route; pages pass nothing down.
 */
export default function ShopLayout({ children }: Props) {
    return (
        <div className="flex min-h-screen flex-col bg-background">
            <ShopHeader />
            <main className="flex-1">{children}</main>
            <ShopFooter />
        </div>
    );
}
