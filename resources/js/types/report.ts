import type { FilterOption, Paginated } from './data-table';

/** The query string every report screen reads (`ReportFilters::toArray()`). */
export type ReportFilters = {
    from: string;
    until: string;
    branch: string;
    category: string;
    group: 'day' | 'week' | 'month';
    search: string;
    state: string;
    sort: string;
    direction: 'asc' | 'desc';
};

/** Props every report page receives besides its deferred `report`. */
export type ReportPageProps<TReport> = {
    filters: ReportFilters;
    /** Deferred: undefined until the figures have loaded. */
    report?: TReport;
    options: {
        branches: FilterOption[];
        categories: FilterOption[];
    };
    can: { export: boolean };
};

/** One bucket of a sales series (`SalesReport::series()`). */
export type SalesPoint = {
    key: string;
    label: string;
    gross: number;
    returned: number;
    net: number;
    orders: number;
    units: number;
};

export type SalesSummary = {
    gross: number;
    discount: number;
    returned: number;
    net: number;
    orders: number;
    units: number;
    average_order: number;
    shipping: number | null;
};

export type SalesCategoryRow = {
    category_id: number | null;
    category_name: string;
    orders: number;
    units: number;
    gross: number;
    returned: number;
    net: number;
};

export type SalesReportData = {
    summary: SalesSummary;
    series: SalesPoint[];
    categories: SalesCategoryRow[];
};

export type PaymentMethodRow = {
    method_name: string;
    method_type: string;
    method_type_label: string;
    attempts: number;
    success_count: number;
    success_amount: number;
    pending_count: number;
    failed_count: number;
    refunded_count: number;
    success_rate: number;
};

export type TransactionReportData = {
    summary: {
        attempts: number;
        success_count: number;
        success_amount: number;
        failed_count: number;
        refunded_amount: number;
    };
    methods: PaymentMethodRow[];
    orders: { status: string; label: string; count: number; amount: number }[];
    refunds: {
        method: string;
        method_label: string;
        status: string;
        status_label: string;
        count: number;
        amount: number;
    }[];
};

export type StockPositionRow = {
    id: number;
    branch_id: number;
    branch_name: string;
    warehouse_id: number;
    warehouse_name: string;
    product_variant_id: number;
    sku: string;
    product_name: string;
    variant_name: string | null;
    quantity: number;
    reserved_quantity: number;
    available_quantity: number;
    min_quantity: number;
    unit_cost: number;
    stock_value: number;
    is_low: boolean;
};

export type StockPositionReportData = {
    summary: {
        rows: number;
        units: number;
        reserved: number;
        value: number;
        low: number;
        out: number;
    };
    rows: Paginated<StockPositionRow>;
};

export type StockCardRow = {
    id: number;
    branch_name: string;
    warehouse_id: number;
    warehouse_name: string;
    product_variant_id: number;
    sku: string;
    product_name: string;
    variant_name: string | null;
    opening: number;
    in: number;
    out: number;
    sold: number;
    closing: number;
};

export type StockCardReportData = {
    summary: {
        opening: number;
        in: number;
        out: number;
        sold: number;
        closing: number;
    };
    rows: Paginated<StockCardRow>;
};

export type BestSellerRow = {
    rank: number;
    sku: string;
    product_id: number | null;
    product_name: string;
    variant_name: string | null;
    orders: number;
    units: number;
    returned_units: number;
    gross: number;
    net: number;
};

export type BestSellerReportData = {
    rows: Paginated<BestSellerRow>;
};

export type CustomerReportRow = {
    user_id: number;
    customer_name: string;
    customer_email: string;
    orders: number;
    spent: number;
    average_order: number;
    first_order_at: string;
    last_order_at: string;
};

export type CustomerReportData = {
    summary: {
        customers: number;
        new_customers: number;
        returning_customers: number;
        spent: number;
        average_spent: number;
        guest_orders: number;
    };
    rows: Paginated<CustomerReportRow>;
};

export type BranchPerformanceRow = {
    branch_id: number;
    branch_name: string;
    branch_code: string;
    is_active: boolean;
    orders: number;
    units: number;
    gross: number;
    returned: number;
    net: number;
    average_order: number;
    cancelled: number;
    share: number;
};

export type BranchPerformanceReportData = {
    rows: BranchPerformanceRow[];
};
