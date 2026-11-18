import { Link, usePage } from '@inertiajs/react';
import type { ComponentProps, DOMAttributes, ReactNode } from 'react';

/**
 * Anchor attributes without the event handlers, whose element types differ
 * between a plain anchor and Inertia's `Link`.
 */
type Props = Omit<
    ComponentProps<'a'>,
    'href' | keyof DOMAttributes<HTMLAnchorElement>
> & {
    href: string | { url: string };
    /**
     * The host the route is served on, for a link that may cross from the
     * storefront to the panels or back.
     */
    host?: 'storefront' | 'panel';
    children?: ReactNode;
};

/**
 * A link that may lead to the other host (ADR-063, ADR-074).
 *
 * Wayfinder's helpers are plain paths, so a path for the other host is put
 * behind that host's root from the shared `hostRoots` prop. An Inertia visit is
 * an XHR request, which cannot cross to another origin, so such a link — and
 * any absolute URL the server handed over — is a plain anchor that the browser
 * loads itself. A link that stays on this host remains an Inertia `Link`.
 *
 * Decided from shared props and the URL alone, never from `window`, so the
 * server-rendered markup and the hydrated markup agree.
 */
export default function HostLink({ href, host, ...props }: Props) {
    const { hostRoots, panelsReachable } = usePage().props;
    const path = typeof href === 'string' ? href : href.url;
    const onThisHost =
        host === undefined || (host === 'panel') === panelsReachable;

    if (hostRoots && host && !onThisHost) {
        return <a href={hostRoots[host] + path} {...props} />;
    }

    if (/^([a-z][a-z0-9+.-]*:)?\/\//i.test(path)) {
        return <a href={path} {...props} />;
    }

    return <Link href={path} {...props} />;
}
