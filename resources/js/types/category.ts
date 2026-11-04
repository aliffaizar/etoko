/** One row of the category listing (`/admin/categories`). */
export type CategoryListItem = {
    id: number;
    name: string;
    slug: string;
    parent_id: number | null;
    /** 1 for a root. Always 1 on a filtered listing, which reads as a flat list. */
    depth: number;
    /** Ancestor names, outermost first. Empty on a filtered listing. */
    path: string[];
    icon: string | null;
    is_active: boolean;
    sort_order: number;
    child_count: number;
    product_count: number;
    can: {
        update: boolean;
        delete: boolean;
    };
    /**
     * Why deletion is refused for this category, for a tooltip on the disabled
     * action. Null when it is deletable, or when the actor may not delete at all.
     */
    delete_blocked_reason: string | null;
};

/** The category being edited on `/admin/categories/{category}/edit`. */
export type CategoryDetail = {
    id: number;
    parent_id: number | null;
    name: string;
    slug: string;
    description: string | null;
    icon: string | null;
    is_active: boolean;
    sort_order: number;
    child_count: number;
    product_count: number;
    depth: number;
    created_at: string | null;
};

/**
 * A category that may hold the one being saved. `depth` is only there to indent
 * the option, so the tree stays readable inside a flat select.
 */
export type CategoryParentOption = {
    value: number;
    label: string;
    depth: number;
};
