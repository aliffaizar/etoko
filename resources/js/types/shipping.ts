import type { StatusTone } from '@/components/panel/status-badge';

/** Mirrors `App\Enums\ShipmentStatus` — where the parcel is. */
export type ShipmentState =
    | 'pending'
    | 'picked_up'
    | 'in_transit'
    | 'delivered'
    | 'failed'
    | 'returned';

/**
 * How each shipment state reads at a glance, in one place.
 *
 * Deliberately separate from `ORDER_STATUS_TONE`: an order's status is the
 * shop's obligation and this is the parcel's position, and the two are allowed
 * to disagree — a failed delivery leaves the order `shipped` while the parcel
 * sits at the depot.
 */
export const SHIPMENT_STATE_TONE: Record<ShipmentState, StatusTone> = {
    pending: 'neutral',
    picked_up: 'info',
    in_transit: 'info',
    delivered: 'success',
    failed: 'danger',
    returned: 'danger',
};

/** Who reported one scan: the courier, or the branch typing it in. */
export type TrackingEventSource = 'courier' | 'branch';

/** One thing a courier said about a parcel. */
export type ShipmentTrackingEntry = {
    id: number;
    status: ShipmentState;
    status_label: string;
    description: string;
    location: string | null;
    source: TrackingEventSource;
    source_label: string;
    event_at: string;
};

/**
 * A parcel, as every screen reads it.
 *
 * `courier_name` and `service_name` are **snapshots** taken when the goods went
 * out: a courier renamed last week must not rename itself on a delivery note
 * from last month. `tracking_url` is the one live reading, and it is nullable
 * because not every courier publishes a tracking page.
 */
export type ShipmentSummary = {
    id: number;
    order_id: number;
    courier_name: string;
    service_name: string;
    display_name: string;
    tracking_number: string | null;
    tracking_url: string | null;
    cost: number;
    weight_gram: number;
    status: ShipmentState;
    status_label: string;
    estimate_label: string | null;
    is_open: boolean;
    is_dispatched: boolean;
    shipped_at: string | null;
    delivered_at: string | null;
    created_at: string | null;
};

/** A parcel as the customer's own order page reads it. */
export type CustomerShipment = ShipmentSummary & {
    failure_reason: string | null;
    timeline: ShipmentTrackingEntry[];
};

/**
 * A parcel as the panel reads it.
 *
 * Wider than the customer's view by exactly the shop's own workings: the
 * aggregator's booking id, who packed it, and when a provider was last asked.
 */
export type ShipmentDetail = ShipmentSummary & {
    notes: string | null;
    failure_reason: string | null;
    external_id: string | null;
    dispatched_by: string | null;
    tracking_synced_at: string | null;
    /** Whether a provider can be asked where this parcel is. */
    is_trackable: boolean;
    timeline: ShipmentTrackingEntry[];
    can: {
        manage?: boolean;
    };
};

/**
 * One way of sending a parcel, as the checkout screen offers it.
 *
 * `cost` is always the **server's** figure: the client is told the prices but
 * never asked for them, and `PlaceOrder` re-quotes whichever one comes back.
 */
export type ShippingOption = {
    courier_service_id: number;
    courier_id: number;
    courier_name: string;
    courier_code: string;
    service_name: string;
    service_code: string;
    display_name: string;
    cost: number;
    etd_min_day: number | null;
    etd_max_day: number | null;
    estimate_label: string | null;
    description: string | null;
};

/**
 * What the checkout screen is told about sending this cart.
 *
 * Three shapes of answer and the screen has to tell them apart: options;
 * nothing *with* a reason, which is the sentence the server wrote about this
 * address; and nothing with no address chosen yet, which is not a failure.
 */
export type CheckoutShipping = {
    address_id: number | null;
    options: ShippingOption[];
    unavailable_reason: string | null;
};

/**
 * A courier a packer may dispatch with (P2-E2-T4).
 *
 * `cost` is nullable, and that is the useful part: a figure is what the shop
 * *would* have charged for this service, while null means no rate in the table
 * reaches this address. Either way the service can be used — the goods are in
 * somebody's hands and have to go out.
 */
export type CourierServiceChoice = {
    courier_service_id: number;
    courier_id: number;
    courier_name: string;
    service_name: string;
    display_name: string;
    estimate_label: string | null;
    description: string | null;
    cost: number | null;
};
