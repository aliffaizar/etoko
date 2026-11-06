import { Inbox, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';

type Props = {
    title: string;
    description?: string;
    icon?: LucideIcon;
    /** A way out of the empty state, e.g. "Reset filter". */
    action?: ReactNode;
};

/**
 * The storefront's empty state.
 *
 * The same arrangement `DataTable` uses in the panels, as a component the shop
 * pages can reuse — a grid with nothing in it needs the same three things a
 * table does: what is missing, why, and what to do about it.
 */
export function ShopEmpty({ title, description, icon, action }: Props) {
    const Icon = icon ?? Inbox;

    return (
        <Empty className="border py-12">
            <EmptyHeader>
                <EmptyMedia variant="icon">
                    <Icon aria-hidden />
                </EmptyMedia>
                <EmptyTitle>{title}</EmptyTitle>
                {description && (
                    <EmptyDescription>{description}</EmptyDescription>
                )}
            </EmptyHeader>
            {action}
        </Empty>
    );
}
