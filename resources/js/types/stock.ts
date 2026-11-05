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

/*
 * Stock documents (P1-E4-T5, T6, T7).
 *
 * Shapes mirror `App\Support\StockDocumentPresenter`.
 */

/** One line of a goods-in or goods-out document. */
export type StockDocumentLine = {
    /** Absent on a line the form has just added. */
    id?: number;
    product_variant_id: number;
    sku: string;
    display_name: string;
    product_name: string;
    quantity: number;
    unit_cost: number | null;
};

type StockDocumentShared = {
    id: number;
    code: string;
    status: string;
    status_label: string;
    /** False once the document has been posted or cancelled. */
    is_editable: boolean;
    notes: string | null;
    created_by: string | null;
    posted_by: string | null;
    posted_at: string | null;
    total_quantity: number;
    /** Why the document may not be posted, from the server's own check. */
    posting_blocked_reason: string | null;
    items: StockDocumentLine[];
};

export type StockEntryDocument = StockDocumentShared & {
    entry_date: string;
    supplier_name: string | null;
    reference_no: string | null;
};

export type StockIssueDocument = StockDocumentShared & {
    issue_date: string;
    reason: string;
    reason_label: string;
    reference_no: string | null;
};

type StockDocumentRowShared = {
    id: number;
    code: string;
    status: string;
    status_label: string;
    is_editable: boolean;
    date: string;
    item_count: number;
    total_quantity: number;
    created_by: string | null;
    posted_by: string | null;
    posted_at: string | null;
};

export type StockEntryRow = StockDocumentRowShared & {
    supplier_name: string | null;
    reference_no: string | null;
};

export type StockIssueRow = StockDocumentRowShared & {
    reason: string;
    reason_label: string;
    reference_no: string | null;
};

/** How many documents sit in each state. */
export type StockDocumentSummary = {
    draft: number;
    posted: number;
    cancelled: number;
    total: number;
};

/** One line of a counting sheet. */
export type StockOpnameLine = {
    id: number;
    product_variant_id: number;
    sku: string;
    display_name: string;
    product_name: string;
    /** What the system believed when the line was added to the session. */
    system_quantity: number;
    /** Null means not counted yet, which is not the same as counted zero. */
    counted_quantity: number | null;
    difference: number | null;
    notes: string | null;
};

export type StockOpnameDocument = {
    id: number;
    code: string;
    status: string;
    status_label: string;
    is_editable: boolean;
    counted_at: string;
    notes: string | null;
    created_by: string | null;
    approved_by: string | null;
    completed_at: string | null;
    completion_blocked_reason: string | null;
    items: StockOpnameLine[];
};

export type StockOpnameRow = {
    id: number;
    code: string;
    status: string;
    status_label: string;
    is_editable: boolean;
    counted_at: string;
    item_count: number;
    /** Lines somebody has actually counted, out of `item_count`. */
    counted_count: number;
    created_by: string | null;
    approved_by: string | null;
    completed_at: string | null;
};

/** A category option for the opname scope picker. */
export type StockCategoryOption = {
    value: number;
    label: string;
    depth: number;
};

/** One match of the line editor's variant picker. */
export type StockVariantOption = {
    value: number;
    sku: string;
    label: string;
    product_name: string;
    on_hand: number;
    available: number;
};

/** A line as the editor holds it while being typed. */
export type StockLineDraft = {
    product_variant_id: number;
    sku: string;
    display_name: string;
    product_name: string;
    quantity: number;
    unit_cost: number | null;
    /** The warehouse figure at the moment the line was added, for guidance only. */
    available: number | null;
};
