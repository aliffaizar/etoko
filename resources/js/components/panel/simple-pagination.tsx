import { Link } from '@inertiajs/react';
import type { PaginatorLink } from '@/types';

/**
 * Previous and next, and the page numbers between them, for a listing that
 * does not need the full data table (P2-E8).
 *
 * Laravel labels its arrows with HTML entities; they are spelled out here so
 * the text is read as words, not as escapes.
 */
export function SimplePagination({ links }: { links: PaginatorLink[] }) {
    if (links.length <= 3) {
        return null;
    }

    return (
        <nav aria-label="Halaman" className="flex flex-wrap items-center gap-1">
            {links.map((link) => {
                const label = link.label
                    .replace('&laquo; Previous', 'Sebelumnya')
                    .replace('Next &raquo;', 'Berikutnya');

                const className = `min-w-8 rounded-md border px-2 py-1 text-center text-sm ${
                    link.active ? 'bg-muted font-medium' : 'hover:bg-muted'
                }`;

                return link.url ? (
                    <Link
                        key={`${link.label}-${link.url}`}
                        href={link.url}
                        preserveScroll
                        className={className}
                        aria-current={link.active ? 'page' : undefined}
                    >
                        {label}
                    </Link>
                ) : (
                    <span
                        key={link.label}
                        className={`${className} opacity-50`}
                    >
                        {label}
                    </span>
                );
            })}
        </nav>
    );
}
