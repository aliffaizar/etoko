import type { NavItem } from '@/types/navigation';

/** A navigation item that may be hidden unless the user holds a permission. */
export type PanelNavItem = NavItem & {
    permission?: string;
};

/** A labelled section of the panel sidebar, mirroring docs brief section 6. */
export type PanelNavGroup = {
    label: string;
    items: PanelNavItem[];
};
