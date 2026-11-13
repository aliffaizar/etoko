import type { StatusTone } from '@/components/panel/status-badge';
import type { Paginated } from '@/types/data-table';

/** Mirrors `App\Enums\OrderStatus`. */
export type OrderStatus =
    | 'pending_payment'
    | 'paid'
    | 'processing'
    | 'ready_for_pickup'
    | 'shipped'
    | 'delivered'
    | 'completed'
    | 'cancelled'
    | 'expired'
    | 'refunded'
    | 'partially_refunded';

/** Mirrors `App\Enums\PaymentStatus`. */
export type OrderPaymentStatus =
    | 'unpaid'
    | 'pending'
    | 'paid'
    | 'partially_refunded'
    | 'refunded'
    | 'failed'
    | 'expired';

/** Mirrors `App\Enums\FulfillmentType`. */
export type FulfillmentType = 'delivery' | 'pickup';

/** Mirrors `App\Enums\ReservationStatus`. */
export type ReservationStatus = 'active' | 'committed' | 'released';

/**
 * How each order status reads at a glance, in one place.
 *
 * The listing, the detail header, the customer's history and the customer
 * profile all show a status badge; one map is what stops "Dibatalkan" being
 * red on one screen and grey on another.
 */
export const ORDER_STATUS_TONE: Record<OrderStatus, StatusTone> = {
    pending_payment: 'warning',
    paid: 'info',
    processing: 'info',
    ready_for_pickup: 'info',
    shipped: 'info',
    delivered: 'success',
    completed: 'success',
    cancelled: 'danger',
    expired: 'danger',
    refunded: 'neutral',
    partially_refunded: 'neutral',
};

export const PAYMENT_STATUS_TONE: Record<OrderPaymentStatus, StatusTone> = {
    unpaid: 'warning',
    pending: 'warning',
    paid: 'success',
    partially_refunded: 'neutral',
    refunded: 'neutral',
    failed: 'danger',
    expired: 'danger',
};

/** One row of an order listing — the panel's queue and the customer's history. */
export type OrderRow = {
    id: number;
    order_number: string;
    status: OrderStatus;
    status_label: string;
    payment_status: OrderPaymentStatus;
    payment_status_label: string;
    fulfillment_type: FulfillmentType;
    fulfillment_label: string;
    channel: 'online' | 'pos';
    customer_name: string;
    branch: { id: number; name: string } | null;
    item_count: number;
    grand_total: number;
    placed_at: string | null;
    created_at: string | null;
};

/** One line of an order — every descriptive field a checkout snapshot. */
export type OrderItemRow = {
    id: number;
    sku: string;
    product_name: string;
    variant_name: string | null;
    display_name: string;
    image_url: string | null;
    product_slug: string | null;
    unit_price: number;
    quantity: number;
    discount_amount: number;
    subtotal: number;
    weight_gram: number;
    refunded_quantity: number;
};

/**
 * One step of the timeline.
 *
 * `is_note` marks a row that is not a status change at all — a branch
 * reassignment records itself as a note on the order (P1-E7-T10).
 */
export type OrderTimelineEntry = {
    id: number;
    from_status: OrderStatus | null;
    from_label: string | null;
    to_status: OrderStatus;
    to_label: string;
    is_note: boolean;
    note: string | null;
    changed_by: string | null;
    created_at: string | null;
};

export type OrderShipping = {
    recipient: string | null;
    phone: string | null;
    address_line: string | null;
    district_name: string | null;
    city_name: string | null;
    province_name: string | null;
    postal_code: string | null;
};

export type OrderTotals = {
    subtotal: number;
    discount_total: number;
    shipping_cost: number;
    tax_total: number;
    grand_total: number;
    total_weight_gram: number;
};

export type OrderMoments = {
    placed_at: string | null;
    paid_at: string | null;
    processed_at: string | null;
    /** When a Click & Collect order's goods started waiting (P2-E2-T7). */
    ready_at: string | null;
    shipped_at: string | null;
    completed_at: string | null;
    cancelled_at: string | null;
};

export type OrderDetail = OrderRow & {
    customer_email: string | null;
    customer_phone: string | null;
    customer_id: number | null;
    shipping: OrderShipping | null;
    totals: OrderTotals;
    voucher_code: string | null;
    customer_note: string | null;
    internal_note: string | null;
    cancel_reason: string | null;
    moments: OrderMoments;
    items: OrderItemRow[];
    timeline: OrderTimelineEntry[];
    can: {
        update_status?: boolean;
        cancel?: boolean;
        assign_branch?: boolean;
        /** Whether the customer may still start or retry a payment (P2-E1-T3). */
        pay?: boolean;
        /** Whether the goods may be handed to a courier now (P2-E2-T4). */
        dispatch_shipment?: boolean;
        /** Whether a Click & Collect order may be handed over (P2-E2-T7). */
        confirm_pickup?: boolean;
        /** Whether the customer may still ask to send goods back (P2-E4-T2). */
        request_return?: boolean;
    };
};

/** A hold the order is carrying, as the panel detail screen shows it. */
export type OrderReservationRow = {
    id: number;
    sku: string | null;
    warehouse_name: string | null;
    quantity: number;
    status: ReservationStatus;
    status_label: string;
    expires_at: string | null;
};

export type PaginatedOrders = Paginated<OrderRow>;

/** One line of a packing list: what to take from one warehouse (P3-E6-T4). */
export type PackingLine = {
    sku: string;
    product_name: string;
    variant_name: string | null;
    quantity: number;
    shelf_location: string | null;
};

/** The lines of an order that come out of one warehouse. */
export type PackingPick = {
    warehouse: string;
    lines: PackingLine[];
};

/** A paid or in-progress order as the warehouse reads it (`PackingListPresenter`). */
export type PackingOrder = {
    id: number;
    order_number: string;
    status: string;
    status_label: string;
    fulfillment_type: 'delivery' | 'pickup';
    fulfillment_label: string;
    customer_name: string;
    customer_note: string | null;
    paid_at: string | null;
    processed_at: string | null;
    unit_count: number;
    picks: PackingPick[];
};
