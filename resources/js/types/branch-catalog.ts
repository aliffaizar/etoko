import type { CategoryParentOption } from '@/types/category';

/** The branch whose catalogue is being configured. */
export type BranchCatalogBranch = {
    id: number;
    name: string;
    code: string;
};

/**
 * One product row of `/admin/branches/{branch}/products` (P1-E3-T9).
 *
 * `is_available` is the answer, and `has_setting` says whether the branch
 * actually decided it — no row means the branch follows the default.
 */
export type BranchProductRow = {
    id: number;
    name: string;
    sku: string | null;
    category_name: string;
    /** Whole rupiah (ADR-008). */
    base_price: number;
    is_active: boolean;
    is_published: boolean;
    is_available: boolean;
    /** False when the branch has said nothing and follows the default. */
    has_setting: boolean;
    notes: string | null;
};

export type BranchProductSummary = {
    total: number;
    available: number;
    unavailable: number;
};

/** How a branch price stands right now. */
export type BranchPriceState = 'active' | 'scheduled' | 'expired' | 'inactive';

/** A branch's own price for one variant, when it has set one. */
export type BranchPriceDetail = {
    id: number;
    /** Whole rupiah (ADR-008). */
    price: number;
    compare_at_price: number | null;
    /** `YYYY-MM-DDTHH:MM` for a `datetime-local` input, or null. */
    starts_at: string | null;
    ends_at: string | null;
    is_active: boolean;
    state: BranchPriceState;
    /** Whether this price is the one actually being charged right now. */
    is_in_force: boolean;
};

/**
 * One variant row of `/admin/branches/{branch}/prices` (P1-E3-T10).
 *
 * `effective_price` and its source come from `EffectivePriceResolver`, so the
 * screen never re-derives the priority order (BR-8).
 */
export type BranchPriceRow = {
    id: number;
    sku: string;
    name: string | null;
    display_name: string;
    product_id: number;
    product_name: string;
    category_name: string;
    is_active: boolean;
    /** What the catalogue charges, before any branch price. */
    catalogue_price: number;
    effective_price: number;
    effective_source: string;
    effective_source_label: string;
    compare_at_price: number | null;
    branch_price: BranchPriceDetail | null;
};

export type BranchPriceSummary = {
    total: number;
    custom: number;
    in_force: number;
};

export type BranchCatalogOptions = {
    categories: CategoryParentOption[];
};
