import type { StatusTone } from '@/components/panel/status-badge';
import type { FilterOption, Paginated } from './data-table';

export type VoucherTypeValue = 'percentage' | 'fixed' | 'free_shipping';
export type VoucherAppliesToValue = 'all' | 'category' | 'product';
export type VoucherChannelValue = 'all' | 'online' | 'pos';

export type VoucherStateBadge = {
    value: string;
    label: string;
    tone: StatusTone;
};

/** One row of `/admin/vouchers`. */
export type VoucherListItem = {
    id: number;
    code: string;
    name: string;
    type: { value: VoucherTypeValue; label: string };
    benefit: string;
    min_order_amount: number;
    quota: number | null;
    used_count: number;
    starts_at: string | null;
    ends_at: string | null;
    is_active: boolean;
    state: VoucherStateBadge;
    can: { update: boolean; delete: boolean };
    delete_blocked_reason: string | null;
};

export type PaginatedVouchers = Paginated<VoucherListItem>;

/** The voucher on `/admin/vouchers/{voucher}/edit`. */
export type VoucherDetail = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    type: VoucherTypeValue;
    value: number;
    max_discount: number | null;
    min_order_amount: number;
    quota: number | null;
    used_count: number;
    per_user_limit: number;
    applies_to: VoucherAppliesToValue;
    channel: VoucherChannelValue;
    starts_at: string | null;
    ends_at: string | null;
    is_active: boolean;
    branch_ids: number[];
    category_ids: number[];
    product_ids: number[];
    state: VoucherStateBadge;
    benefit: string;
};

/** One redemption, listed under the edit form. */
export type VoucherUsageRow = {
    id: number;
    order_id: number;
    order_number: string;
    customer_name: string;
    discount_amount: number;
    used_at: string;
    released_at: string | null;
};

export type VoucherPickOption = {
    value: number;
    label: string;
    /** Categories only: tree depth, for indenting. */
    depth?: number;
};

export type VoucherFormOptions = {
    types: FilterOption[];
    applies_to: FilterOption[];
    channels: FilterOption[];
    branches: VoucherPickOption[];
    categories: VoucherPickOption[];
    products: VoucherPickOption[];
};

/**
 * The cart's voucher as the cart and checkout screens read it
 * (`VoucherPresenter::forBasket`). `shipping_discount` is null while the
 * delivery charge is not known; checkout works it out for the chosen courier.
 */
export type BasketVoucher = {
    code: string;
    name: string;
    benefit: string;
    type: VoucherTypeValue;
    discounts_shipping: boolean;
    max_discount: number | null;
    is_accepted: boolean;
    rejection: string | null;
    goods_discount: number;
    shipping_discount: number | null;
};
