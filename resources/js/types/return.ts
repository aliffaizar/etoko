import type { StatusTone } from '@/components/panel/status-badge';
import type { Paginated } from '@/types/data-table';
import type {
    OrderItemRow,
    OrderPaymentStatus,
    OrderStatus,
} from '@/types/order';

/** Mirrors `App\Enums\ReturnType`. */
export type ReturnKind = 'refund' | 'exchange';

/** Mirrors `App\Enums\ReturnStatus`. */
export type ReturnState =
    | 'requested'
    | 'approved'
    | 'rejected'
    | 'received'
    | 'completed'
    | 'cancelled';

/** Mirrors `App\Enums\ReturnReason`. */
export type ReturnReason =
    | 'damaged'
    | 'wrong_item'
    | 'not_as_described'
    | 'missing_item'
    | 'changed_mind'
    | 'other';

/** Mirrors `App\Enums\ReturnItemCondition`. */
export type ReturnItemCondition = 'good' | 'damaged';

/** Mirrors `App\Enums\RefundMethod`. */
export type RefundMethod = 'transfer' | 'gateway' | 'cash' | 'store_credit';

/** Mirrors `App\Enums\RefundStatus`. */
export type RefundState = 'pending' | 'processed' | 'failed';

/**
 * How each return state reads at a glance, in one place.
 *
 * `received` is `info` rather than `success`: the goods are back but the
 * customer has not been paid yet, which is precisely the state somebody still
 * has work to do in.
 */
export const RETURN_STATE_TONE: Record<ReturnState, StatusTone> = {
    requested: 'warning',
    approved: 'info',
    rejected: 'danger',
    received: 'info',
    completed: 'success',
    cancelled: 'neutral',
};

export const REFUND_STATE_TONE: Record<RefundState, StatusTone> = {
    pending: 'warning',
    processed: 'success',
    failed: 'danger',
};

/** The order a return or refund belongs to, as a listing shows it. */
export type ReturnOrderSummary = {
    id: number;
    order_number: string;
    customer_name: string;
    status: OrderStatus;
    status_label: string;
    payment_status: OrderPaymentStatus;
    payment_status_label: string;
    grand_total: number;
};

/** One row of the panel's return queue. */
export type ReturnRow = {
    id: number;
    code: string;
    type: ReturnKind;
    type_label: string;
    reason: ReturnReason;
    reason_label: string;
    status: ReturnState;
    status_label: string;
    item_count: number;
    photo_count: number;
    requested_at: string | null;
    approved_at: string | null;
    received_at: string | null;
    completed_at: string | null;
    created_at: string | null;
    order: ReturnOrderSummary | null;
    branch: { id: number; name: string } | null;
};

/**
 * One line of a return, described through the order line it came from.
 *
 * `is_restockable` is **not** the same as `restock`: the first says whether
 * these units could go back on a shelf at all — a variant deleted from the
 * catalogue cannot — and the second is what the branch decided. The receiving
 * form disables the tick box when the first is false rather than offering a
 * control the server will silently overrule.
 */
export type ReturnItemRow = {
    id: number;
    order_item_id: number;
    sku: string | null;
    display_name: string | null;
    image_url: string | null;
    ordered_quantity: number;
    quantity: number;
    unit_value: number;
    refundable_value: number;
    condition: ReturnItemCondition;
    condition_label: string;
    restock: boolean;
    restocked_at: string | null;
    restock_warehouse: string | null;
    is_restockable: boolean;
    notes: string | null;
};

/** One refund, as a listing or a return's detail screen shows it. */
export type RefundRow = {
    id: number;
    code: string;
    amount: number;
    method: RefundMethod;
    method_label: string;
    status: RefundState;
    status_label: string;
    reference: string | null;
    account_info: string | null;
    failure_reason: string | null;
    notes: string | null;
    return_id: number | null;
    return_code: string | null;
    payment_id: number | null;
    payment_reference: string | null;
    processed_by: string | null;
    processed_at: string | null;
    created_at: string | null;
    order: Omit<ReturnOrderSummary, 'status' | 'status_label'> | null;
    branch: { id: number; name: string } | null;
};

/** One return, as the panel's detail screen reads it. */
export type ReturnDetail = ReturnRow & {
    description: string | null;
    photo_urls: string[];
    rejection_reason: string | null;
    notes: string | null;
    /** What the returned goods were sold for. */
    goods_value: number;
    /** What has actually gone back — settled refunds only. */
    refunded_amount: number;
    requested_by: string | null;
    approved_by: string | null;
    received_by: string | null;
    items: ReturnItemRow[];
    refunds: RefundRow[];
    can: {
        approve?: boolean;
        reject?: boolean;
        receive?: boolean;
        complete?: boolean;
        refund?: boolean;
        /** Whether an existing refund may be settled or failed. */
        process_refund?: boolean;
    };
    blocked: {
        approve?: string | null;
        reject?: string | null;
        receive?: string | null;
        refund?: string | null;
    };
};

/** A way of refunding, as a choice on a form. */
export type RefundMethodChoice = {
    value: RefundMethod;
    label: string;
    is_automatic: boolean;
    needs_account_info: boolean;
    is_available: boolean;
    unavailable_reason: string | null;
};

/** One return as the customer's own order page reads it. */
export type CustomerReturn = {
    id: number;
    code: string;
    type: ReturnKind;
    type_label: string;
    reason: ReturnReason;
    reason_label: string;
    description: string | null;
    photo_urls: string[];
    status: ReturnState;
    status_label: string;
    rejection_reason: string | null;
    item_count: number;
    items: { id: number; display_name: string | null; quantity: number }[];
    can_cancel: boolean;
    refunded_amount: number;
    requested_at: string | null;
    approved_at: string | null;
    received_at: string | null;
    completed_at: string | null;
};

/** One refund as the customer's own order page reads it. */
export type CustomerRefund = {
    id: number;
    code: string;
    amount: number;
    method: RefundMethod;
    method_label: string;
    status: RefundState;
    status_label: string;
    reference: string | null;
    processed_at: string | null;
    created_at: string | null;
};

/**
 * An order line as a choice on the customer's request form.
 *
 * `returnable_quantity` is what the stepper is capped at, and it is the same
 * number `RequestReturn` re-checks under the order's row lock.
 */
export type ReturnableLine = OrderItemRow & {
    returned_quantity: number;
    returnable_quantity: number;
};

export type PaginatedReturns = Paginated<ReturnRow>;
export type PaginatedRefunds = Paginated<RefundRow>;
