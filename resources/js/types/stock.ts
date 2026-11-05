import type { Paginated } from '@/types/data-table';

/** The branch an inventory screen is working in. */
export type StockBranch = {
    id: number;
    name: string;
    code: string;
};

/** The warehouse an inventory screen is working in. */
export type StockWarehouse = {
    id: number;
    name: string;
    code: string;
    is_default: boolean;
};

/** An option of the branch or warehouse selector. */
export type StockScopeOption = {
    value: string;
    label: string;
};

/**
 * Where an inventory screen is looking, as `BranchWorkspace::toArray()` sends
 * it. Spread into every inventory page's props.
 */
export type StockWorkspaceProps = {
    branch: StockBranch;
    warehouse: StockWarehouse;
    warehouses: StockScopeOption[];
    /** Empty for branch staff, who have exactly one branch (BR-3). */
    branches: StockScopeOption[];
    may_choose_branch: boolean;
};

/**
 * One row of the branch stock listing.
 *
 * `on_hand` is what is on the shelf, `reserved` is what checkout has already
 * promised a buyer, and `available` is the difference — the only figure a sale
 * may draw on (BR-6).
 */
export type StockRow = {
    id: number;
    sku: string;
    name: string | null;
    display_name: string;
    product_id: number;
    product_name: string;
    category_name: string;
    is_active: boolean;
    on_hand: number;
    reserved: number;
    available: number;
    min_quantity: number;
    max_quantity: number | null;
    shelf_location: string | null;
    is_low: boolean;
};

export type StockSummary = {
    /** Variants in the catalogue, stocked here or not. */
    variants: number;
    /** Variants this warehouse holds at least one of. */
    stocked: number;
    /** Variants at or below their own minimum. */
    low: number;
    on_hand: number;
    reserved: number;
};

/** The variant whose history is on show (P1-E4-T8). */
export type StockMovementVariant = {
    id: number;
    sku: string;
    name: string | null;
    display_name: string;
    product_id: number;
    product_name: string;
};

/** The warehouse's current figures for that variant. */
export type StockFigures = {
    on_hand: number;
    reserved: number;
    available: number;
    min_quantity: number;
    shelf_location: string | null;
    is_low: boolean;
};

/** One row of the ledger, as the history screen reads it. */
export type StockMovementRow = {
    id: number;
    type: string;
    type_label: string;
    /** Signed: positive for stock arriving, negative for stock leaving. */
    quantity_change: number;
    quantity_before: number;
    quantity_after: number;
    unit_cost: number | null;
    notes: string | null;
    /** Code of the document behind the movement, when there is one. */
    reference_label: string | null;
    created_by: string | null;
    created_at: string | null;
};

export type StockMovementsProps = StockWorkspaceProps & {
    variant: StockMovementVariant;
    stock: StockFigures;
    movements: Paginated<StockMovementRow>;
    filters: Record<string, string>;
    options: { types: StockScopeOption[] };
};
